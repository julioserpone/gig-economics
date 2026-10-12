<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelVolume;
use JsonSerializable;

/**
 * Aggregated spend, volume and distance for one calendar period (usually a month).
 */
final class PeriodSummary implements JsonSerializable
{
    public function __construct(
        public readonly string $period,
        public readonly int $purchaseCount,
        public readonly FuelVolume $totalVolume,
        public readonly Money $totalSpend,
        public readonly Distance $totalDistance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period,
            'purchase_count' => $this->purchaseCount,
            'total_volume' => (string) $this->totalVolume,
            'total_spend' => (string) $this->totalSpend,
            'total_distance' => (string) $this->totalDistance,
        ];
    }
}
