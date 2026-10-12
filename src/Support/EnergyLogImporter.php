<?php

declare(strict_types=1);

namespace GigEconomics\Support;

use DateTimeImmutable;
use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Energy\EnergyPrice;
use GigEconomics\Domain\Energy\EnergyPurchase;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * Reads a driver's replenishment export into an {@see EnergyLog}.
 *
 * The file is pipe-delimited (`|`) rather than comma-separated, because vendor
 * names and other free-text fields routinely contain commas. Every field is
 * wrapped in double quotes and a UTF-8 BOM may be present, both of which are
 * tolerated. The header-driven layout works for both combustion and electric
 * vehicles. Recognized input columns (case-insensitive, spaces and underscores
 * ignored) are:
 *
 *   date | datetime        when the purchase happened
 *   time                  separate time column, combined with `date`
 *   vendor_tax_id         vendor tax identifier
 *   vendor_name           vendor display name
 *   energy | quantity     amount of energy (gallons, litres or kWh)
 *   unit_price            price per unit of energy, before tax
 *   odometer              meter reading at the moment of purchase
 *   energy_type           one of the {@see EnergyType} values (optional)
 *   tax_rate              tax percentage applied to the subtotal (optional)
 *
 * Only input columns are read. Any calculated column the export may also carry
 * is ignored and recomputed by the domain, so a stale formula can never leak
 * into the numbers.
 */
final class EnergyLogImporter
{
    /**
     * @param array<int, array<string, string|null>> $rows
     */
    public function fromRows(
        array $rows,
        float $taxRatePercent = 15.0,
        EnergyType $energyType = EnergyType::RegularGasoline,
    ): EnergyLog {
        $purchases = [];

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeRow($row);
            $energyAmount = $this->decimal($normalized['energy'] ?? $normalized['quantity'] ?? null);
            $unitPrice = $this->decimal($normalized['unit price'] ?? $normalized['unitprice'] ?? null);

            // Rows reserved for capacity (no amount, no price) are skipped.
            if ($energyAmount === null || $unitPrice === null || $energyAmount === '0') {
                continue;
            }

            $rowType = $this->energyType($normalized['energy type'] ?? null) ?? $energyType;
            $quantity = EnergyQuantity::of($energyAmount, $rowType->unit());

            $purchases[] = EnergyPurchase::create(
                energy: $quantity,
                unitPrice: $this->priceFor($unitPrice, $rowType),
                purchasedAt: $this->dateTime(
                    $normalized['date'] ?? $normalized['datetime'] ?? null,
                    $normalized['time'] ?? null,
                ),
                vendorTaxId: $this->nullableString($normalized['vendor tax id'] ?? null),
                vendorName: $this->nullableString($normalized['vendor name'] ?? null),
                odometer: $this->integer($normalized['odometer'] ?? null),
                energyType: $rowType,
                taxRate: $this->taxRate($normalized['tax rate'] ?? null, $taxRatePercent),
                reference: 'csv-row-'.$index,
            );
        }

        return EnergyLog::fromPurchases($purchases);
    }

    /**
     * Parse a raw pipe-delimited string.
     *
     * The first non-empty line is the header; every following line is a record.
     * A UTF-8 BOM, blank lines and the surrounding quotes of each field are
     * normalized here.
     */
    public function fromString(
        string $contents,
        float $taxRatePercent = 15.0,
        EnergyType $energyType = EnergyType::RegularGasoline,
    ): EnergyLog {
        $contents = $this->stripBom($contents);
        $lines = preg_split('/\r\n|\r|\n/', trim($contents));

        if ($lines === false) {
            $lines = [];
        }

        $rows = [];

        $header = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = $this->splitLine($line);

            if ($header === null) {
                $header = array_map(fn (string $label): string => $this->key($label), $cells);

                continue;
            }

            $rows[] = $this->combine($header, $cells);
        }

        return $this->fromRows($rows, $taxRatePercent, $energyType);
    }

    public function fromFile(
        string $path,
        float $taxRatePercent = 15.0,
        EnergyType $energyType = EnergyType::RegularGasoline,
    ): EnergyLog {
        if (! is_file($path)) {
            throw InvalidDomainValueException::outOfRange('csv path', $path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw InvalidDomainValueException::outOfRange('csv path', $path);
        }

        return $this->fromString($contents, $taxRatePercent, $energyType);
    }

    /**
     * Build the unit price using the unit implied by the energy type.
     */
    private function priceFor(string $amount, EnergyType $energyType): EnergyPrice
    {
        return match ($energyType) {
            EnergyType::Electric => EnergyPrice::perKilowattHour($amount),
            default => EnergyPrice::perGallon($amount),
        };
    }

    /**
     * Split one pipe-delimited line into trimmed, unquoted fields.
     *
     * Fields are separated by a single `|` and wrapped in double quotes. Using
     * a pipe avoids the ambiguity of commas inside free-text fields such as
     * vendor names, so a plain split is correct and predictable.
     *
     * @return array<int, string>
     */
    private function splitLine(string $line): array
    {
        $cells = explode('|', $line);

        return array_map(
            static fn (string $cell): string => trim(trim($cell), '"'),
            $cells,
        );
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF")
            ? substr($value, 3)
            : str_replace("\u{FEFF}", '', $value);
    }

    /**
     * @param array<int, string> $header
     * @param array<int, string> $cells
     * @return array<string, string|null>
     */
    private function combine(array $header, array $cells): array
    {
        $row = [];

        foreach ($header as $position => $label) {
            $row[$label] = $cells[$position] ?? null;
        }

        return $row;
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, string|null>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->key($key)] = $value;
        }

        return $normalized;
    }

    private function key(string $label): string
    {
        $label = str_replace(['"', "\u{FEFF}"], '', $label);
        $label = strtolower(trim($label));
        $label = (string) preg_replace('/[_\s]+/', ' ', $label);

        return $label;
    }

    private function energyType(?string $value): ?EnergyType
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        return EnergyType::tryFrom(strtolower(str_replace(' ', '_', $value)));
    }

    private function taxRate(?string $value, float $default): Percentage
    {
        $decimal = $this->decimal($value);

        return Percentage::of($decimal ?? (string) $default);
    }

    private function decimal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strcasecmp($value, 'nan') === 0 || str_starts_with($value, 'NaN')) {
            return null;
        }

        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? $value : null;
    }

    private function integer(?string $value): ?int
    {
        $decimal = $this->decimal($value);

        return $decimal === null ? null : (int) $decimal;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return ($value === '') ? null : $value;
    }

    private function dateTime(?string $date, ?string $time): ?DateTimeImmutable
    {
        $date = $this->nullableString($date);

        if ($date === null || str_contains($date, 'NaN')) {
            return null;
        }

        $time = $this->nullableString($time) ?? '00:00:00';
        $candidate = sprintf('%s %s', $date, $time);

        foreach (['Y-m-d H:i:s', 'd/m/Y H:i:s', 'Y-m-d H:i', 'd/m/Y H:i', 'Y-m-d', 'd/m/Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $candidate);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
