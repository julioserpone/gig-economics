<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Fuel;

use DateTimeImmutable;
use DateTimeInterface;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\FuelType;
use GigEconomics\Domain\Shared\FuelVolume;
use GigEconomics\Domain\Shared\TimeOfDay;

/**
 * A single refuelling event.
 *
 * Mirrors one row of `TblGasolina` and reproduces every calculated column:
 * `Valor total`, `Iva 15%`, `Monto`, `Km Recorridos` and `Km/Galón`.
 */
final class FuelPurchase
{
    public function __construct(
        public readonly FuelVolume $volume,
        public readonly FuelPrice $unitPrice,
        public readonly ?DateTimeImmutable $purchasedAt = null,
        public readonly ?string $vendorTaxId = null,
        public readonly ?string $vendorName = null,
        public readonly ?int $odometer = null,
        public readonly ?FuelType $fuelType = null,
        public readonly ?Percentage $taxRate = null,
        public readonly ?string $reference = null,
    ) {}

    /**
     * Convenience factory matching the workbook's input columns.
     */
    public static function create(
        FuelVolume $volume,
        FuelPrice $unitPrice,
        ?DateTimeInterface $purchasedAt = null,
        ?string $vendorTaxId = null,
        ?string $vendorName = null,
        ?int $odometer = null,
        ?FuelType $fuelType = null,
        ?Percentage $taxRate = null,
        ?string $reference = null,
    ): self {
        return new self(
            $volume,
            $unitPrice,
            self::toImmutable($purchasedAt),
            $vendorTaxId,
            $vendorName,
            $odometer,
            $fuelType,
            $taxRate,
            $reference,
        );
    }

    /**
     * `Valor total`: subtotal before tax.
     */
    public function subtotal(): Money
    {
        return $this->unitPrice->costOf($this->volume);
    }

    /**
     * `Iva 15%`: tax applied on the subtotal.
     */
    public function tax(): Money
    {
        $rate = $this->taxRate ?? Percentage::zero();

        return $this->subtotal()->percentage($rate);
    }

    /**
     * `Monto`: total paid including tax.
     */
    public function total(): Money
    {
        return $this->subtotal()->plus($this->tax());
    }

    public function currency(): Currency
    {
        return $this->unitPrice->currency();
    }

    public function purchasedOn(): ?string
    {
        return $this->purchasedAt?->format('Y-m-d');
    }

    public function timeOfDay(): ?TimeOfDay
    {
        return $this->purchasedAt === null ? null : TimeOfDay::fromDateTime($this->purchasedAt);
    }

    public function hasOdometer(): bool
    {
        return $this->odometer !== null;
    }

    /**
     * `Km/Galón` for an explicit travelled distance.
     */
    public function efficiencyFor(Distance $travelled): FuelEfficiency
    {
        return FuelEfficiency::fromDistanceAndVolume($travelled, $this->volume);
    }

    private static function toImmutable(?DateTimeInterface $moment): ?DateTimeImmutable
    {
        if ($moment === null) {
            return null;
        }

        return $moment instanceof DateTimeImmutable
            ? $moment
            : DateTimeImmutable::createFromInterface($moment);
    }
}
