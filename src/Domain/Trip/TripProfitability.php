<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\Platform;

/**
 * Full profitability assessment of a single trip offer.
 */
final class TripProfitability
{
    public function __construct(
        public readonly Platform $platform,
        public readonly TripCostBreakdown $breakdown,
        public readonly TripVerdict $verdict,
        public readonly Money $breakEvenFare,
    ) {}

    public function isProfitable(): bool
    {
        return $this->breakdown->isProfitable();
    }

    public function netProfit(): Money
    {
        return $this->breakdown->netProfit;
    }

    public function distance(): Distance
    {
        return $this->breakdown->distance;
    }

    public function verdict(): TripVerdict
    {
        return $this->verdict;
    }

    /**
     * Gross fare the platform would have to pay for this trip to break even.
     *
     * Solved from `gross - commission - costs = 0` with a percentage commission:
     * `breakEven = costs / (1 - commissionRate)`.
     */
    public function breakEvenFare(): Money
    {
        return $this->breakEvenFare;
    }
}
