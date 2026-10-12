<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Energy;

use DateTimeImmutable;
use DateTimeInterface;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\TimeOfDay;

/**
 * A single replenishment event, whether refuelling a combustion vehicle or
 * recharging an electric one.
 *
 * A purchase always carries the amount of energy taken on board (gallons,
 * litres or kilowatt-hours), the price paid per unit, and optionally the meter
 * reading at the moment of replenishment, from which the distance covered since
 * the previous purchase is derived.
 */
final class EnergyPurchase
{
    public function __construct(
        public readonly EnergyQuantity $energy,
        public readonly EnergyPrice $unitPrice,
        public readonly ?DateTimeImmutable $purchasedAt = null,
        public readonly ?string $vendorTaxId = null,
        public readonly ?string $vendorName = null,
        public readonly ?int $odometer = null,
        public readonly ?EnergyType $energyType = null,
        public readonly ?Percentage $taxRate = null,
        public readonly ?string $reference = null,
    ) {}

    /**
     * Named constructor mirroring the constructor for call-site readability.
     */
    public static function create(
        EnergyQuantity $energy,
        EnergyPrice $unitPrice,
        ?DateTimeInterface $purchasedAt = null,
        ?string $vendorTaxId = null,
        ?string $vendorName = null,
        ?int $odometer = null,
        ?EnergyType $energyType = null,
        ?Percentage $taxRate = null,
        ?string $reference = null,
    ): self {
        return new self(
            $energy,
            $unitPrice,
            self::toImmutable($purchasedAt),
            $vendorTaxId,
            $vendorName,
            $odometer,
            $energyType,
            $taxRate,
            $reference,
        );
    }

    /**
     * Subtotal before tax: energy times unit price.
     */
    public function subtotal(): Money
    {
        return $this->unitPrice->costOf($this->energy);
    }

    /**
     * Tax applied on the subtotal.
     */
    public function tax(): Money
    {
        $rate = $this->taxRate ?? Percentage::zero();

        return $this->subtotal()->percentage($rate);
    }

    /**
     * Total paid including tax.
     */
    public function total(): Money
    {
        return $this->subtotal()->plus($this->tax());
    }

    public function currency(): Currency
    {
        return $this->unitPrice->currency();
    }

    /**
     * Whether this purchase replenished an electric vehicle.
     */
    public function isElectric(): bool
    {
        return $this->energyType?->isElectric()
            ?? $this->energy->isElectric();
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
     * Energy economy (distance per unit of energy) for an explicit travelled
     * distance.
     */
    public function economyFor(Distance $travelled): EnergyEconomy
    {
        return EnergyEconomy::fromDistanceAndEnergy($travelled, $this->energy);
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
