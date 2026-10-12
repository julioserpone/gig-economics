<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\FuelVolume;
use JsonSerializable;

/**
 * Headline fuel KPIs, equivalent to the workbook's DASHBOARD block.
 */
final class FuelKpis implements JsonSerializable
{
    public function __construct(
        public readonly int $purchaseCount,
        public readonly FuelVolume $totalVolume,
        public readonly Money $totalSpend,
        public readonly Money $totalSubtotal,
        public readonly BigDecimal $weightedPricePerGallon,
        public readonly ?FuelEfficiency $averageEfficiency,
        public readonly int $vendorCount,
        public readonly int $validEfficiencyCount,
        public readonly Distance $totalDistance,
    ) {}

    /**
     * Total tax paid across every purchase.
     */
    public function totalTax(): Money
    {
        return $this->totalSpend->minus($this->totalSubtotal);
    }

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
            'total_volume' => (string) $this->totalVolume,
            'total_spend' => (string) $this->totalSpend,
            'total_subtotal' => (string) $this->totalSubtotal,
            'total_tax' => (string) $this->totalTax(),
            'weighted_price_per_gallon' => (string) $this->weightedPricePerGallon,
            'average_efficiency' => $this->averageEfficiency === null ? null : (string) $this->averageEfficiency,
            'vendor_count' => $this->vendorCount,
            'valid_efficiency_count' => $this->validEfficiencyCount,
            'total_distance' => (string) $this->totalDistance,
        ];
    }
}
