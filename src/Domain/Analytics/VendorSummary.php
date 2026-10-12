<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use Brick\Math\BigDecimal;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use JsonSerializable;

/**
 * Aggregated performance of one energy vendor: a filling station for combustion
 * vehicles, or a charging operator for electric ones.
 */
final class VendorSummary implements JsonSerializable
{
    public function __construct(
        public readonly string $vendorName,
        public readonly ?string $vendorTaxId,
        public readonly int $purchaseCount,
        public readonly EnergyQuantity $totalEnergy,
        public readonly Money $totalSpend,
        public readonly BigDecimal $weightedPricePerUnit,
        public readonly ?EnergyEconomy $averageEconomy,
        public readonly int $validEconomyCount,
    ) {}

    /**
     * A vendor is only ranked by economy once it has at least two readings,
     * because a single reading is anecdotal.
     */
    public function isRankableForEconomy(int $minimumPurchases = 2): bool
    {
        return $this->validEconomyCount >= $minimumPurchases && $this->averageEconomy !== null;
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
            'total_energy' => (string) $this->totalEnergy,
            'energy_unit' => $this->totalEnergy->unit->value,
            'total_spend' => (string) $this->totalSpend,
            'weighted_price_per_unit' => (string) $this->weightedPricePerUnit,
            'average_economy' => $this->averageEconomy === null ? null : (string) $this->averageEconomy,
            'valid_economy_count' => $this->validEconomyCount,
        ];
    }
}
