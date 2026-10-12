<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use Brick\Math\BigDecimal;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\FuelVolume;
use JsonSerializable;

/**
 * Aggregated performance of one fuel vendor (gas station).
 */
final class VendorSummary implements JsonSerializable
{
    public function __construct(
        public readonly string $vendorName,
        public readonly ?string $vendorTaxId,
        public readonly int $purchaseCount,
        public readonly FuelVolume $totalVolume,
        public readonly Money $totalSpend,
        public readonly BigDecimal $weightedPricePerGallon,
        public readonly ?FuelEfficiency $averageEfficiency,
        public readonly int $validEfficiencyCount,
    ) {}

    /**
     * The dashboard only ranks a vendor's efficiency once it has at least two
     * refuels, because a single reading is anecdotal.
     */
    public function isRankableForEfficiency(int $minimumPurchases = 2): bool
    {
        return $this->validEfficiencyCount >= $minimumPurchases && $this->averageEfficiency !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'vendor_name' => $this->vendorName,
            'vendor_tax_id' => $this->vendorTaxId,
            'purchase_count' => $this->purchaseCount,
            'total_volume' => (string) $this->totalVolume,
            'total_spend' => (string) $this->totalSpend,
            'weighted_price_per_gallon' => (string) $this->weightedPricePerGallon,
            'average_efficiency' => $this->averageEfficiency === null ? null : (string) $this->averageEfficiency,
            'valid_efficiency_count' => $this->validEfficiencyCount,
        ];
    }
}
