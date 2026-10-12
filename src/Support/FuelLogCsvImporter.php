<?php

declare(strict_types=1);

namespace GigEconomics\Support;

use DateTimeImmutable;
use GigEconomics\Domain\Fuel\FuelLog;
use GigEconomics\Domain\Fuel\FuelPrice;
use GigEconomics\Domain\Fuel\FuelPurchase;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\FuelType;
use GigEconomics\Domain\Shared\FuelVolume;
use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * Reads the `TblGasolina` CSV layout into a {@see FuelLog}.
 *
 * The expected header is:
 * Fecha, Hora, RUC, Razon social, Galones, Precio/galon, Valor Unitario,
 * Valor total, Iva 15%, Monto, Kilometraje, Km Recorridos, Km/Galon.
 *
 * Only the input columns are read; calculated columns are recomputed by the
 * domain so the importer never trusts derived spreadsheet values.
 */
final class FuelLogCsvImporter
{
    /**
     * @param array<int, array<string, string|null>> $rows
     */
    public function fromRows(array $rows, float $taxRatePercent = 15.0): FuelLog
    {
        $purchases = [];

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeRow($row);
            $gallons = $this->decimal($normalized['galones'] ?? null);
            $unitValue = $this->decimal($normalized['valor unitario'] ?? null);

            // Rows reserved for capacity (no date, no volume, no price) are skipped.
            if ($gallons === null || $unitValue === null || $gallons === '0') {
                continue;
            }

            $purchases[] = FuelPurchase::create(
                volume: FuelVolume::ofGallons($gallons),
                unitPrice: FuelPrice::perGallon($unitValue),
                purchasedAt: $this->dateTime(
                    $normalized['fecha'] ?? null,
                    $normalized['hora'] ?? null,
                ),
                vendorTaxId: $this->nullableString($normalized['ruc'] ?? null),
                vendorName: $this->nullableString($normalized['razon social'] ?? null),
                odometer: $this->integer($normalized['kilometraje'] ?? null),
                fuelType: FuelType::RegularGasoline,
                taxRate: Percentage::of($taxRatePercent),
                reference: 'csv-row-'.$index,
            );
        }

        return FuelLog::fromPurchases($purchases);
    }

    /**
     * Parse a raw CSV string (as exported by the workbook).
     *
     * Spreadsheet exports often wrap every field in doubled quotes
     * (`""Fecha""`) and prepend a UTF-8 BOM; both are normalized here so the
     * same importer handles Excel, Numbers and Google Sheets exports.
     */
    public function fromString(string $csv, float $taxRatePercent = 15.0): FuelLog
    {
        $csv = $this->stripBom($csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));

        if ($lines === false) {
            $lines = [];
        }

        $rows = [];

        $header = null;
        $combined = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = $this->splitLine($line);

            if ($header === null) {
                $header = array_map(fn (string $label): string => $this->key($label), $cells);

                continue;
            }

            $combined[] = $this->combine($header, $cells);
        }

        return $this->fromRows($combined, $taxRatePercent);
    }

    /**
     * Split one CSV line into trimmed, unquoted fields.
     *
     * Spreadsheet exports wrap every field in doubled quotes and separate them
     * with a bare comma. Because such exports do not quote embedded commas,
     * a plain split is the correct and predictable strategy here; it keeps the
     * importer free of the deprecated `str_getcsv()` escape argument.
     *
     * @return array<int, string>
     */
    private function splitLine(string $line): array
    {
        $normalized = str_replace('""', '', $line);
        $cells = explode(',', $normalized);

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

    public function fromFile(string $path, float $taxRatePercent = 15.0): FuelLog
    {
        if (! is_file($path)) {
            throw InvalidDomainValueException::outOfRange('csv path', $path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw InvalidDomainValueException::outOfRange('csv path', $path);
        }

        return $this->fromString($contents, $taxRatePercent);
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
            $row[$this->key($label)] = $cells[$position] ?? null;
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

        return preg_replace('/\s+/', ' ', $label) ?? $label;
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

        foreach (['Y-m-d H:i:s', 'd/m/Y H:i:s', 'Y-m-d H:i', 'd/m/Y H:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $candidate);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
