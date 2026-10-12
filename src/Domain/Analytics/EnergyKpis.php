<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use JsonSerializable;

/**
 * Headline energy KPIs for a whole log.
 *
 * The same object describes a combustion fleet and an electric one; the energy
 * unit carried by {@see EnergyQuantity} says which, and `isElectric()` offers a
 * quick branch for dashboards.
 */
final class EnergyKpis implements JsonSerializable
{
    public function __construct(
        public readonly int $purchaseCount,
        public readonly EnergyQuantity $totalEnergy,
        public readonly Money $totalSpend,
        public readonly Money $totalSubtotal,
        public readonly BigDecimal $weightedPricePerUnit,
        public readonly ?EnergyEconomy $averageEconomy,
        public readonly int $vendorCount,
        public readonly int $validEconomyCount,
        public readonly Distance $totalDistance,
        public readonly bool $isElectric = false,
    ) {}

    /**
     * Total tax paid across every purchase.
     */
    public function totalTax(): Money
    {
        return $this->totalSpend->minus($this->totalSubtotal);
    }

    /**
     * Whether every purchase in the log replenished an electric vehicle.
     */
    public function isElectric(): bool
    {
        return $this->isElectric;
    }

    /**
     * Spend per kilometre driven.
     */
    public function spendPerKilometre(int $scale = 6): ?BigDecimal
    {
        if (! $this->totalDistance->isPositive()) {
            return null;
        }

        return $this->totalSpend->toDecimal()->dividedBy(
            $this->totalDistance->toDecimal(),
            $scale,
            RoundingMode::HALF_UP,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'purchase_count' => $this->purchaseCount,
            'total_energy' => (string) $this->totalEnergy,
            'energy_unit' => $this->totalEnergy->unit->value,
            'is_electric' => $this->isElectric,
            'total_spend' => (string) $this->totalSpend,
            'total_subtotal' => (string) $this->totalSubtotal,
            'total_tax' => (string) $this->totalTax(),
            'weighted_price_per_unit' => (string) $this->weightedPricePerUnit,
            'average_economy' => $this->averageEconomy === null ? null : (string) $this->averageEconomy,
            'vendor_count' => $this->vendorCount,
            'valid_economy_count' => $this->validEconomyCount,
            'total_distance' => (string) $this->totalDistance,
        ];
    }
}
