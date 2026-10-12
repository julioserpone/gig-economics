<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;

/**
 * Immutable, itemized view of what a trip cost and what it actually paid.
 *
 * Produced by {@see TripProfitabilityCalculator} and consumed by the frontend
 * or the mobile app; it is a plain value object with no persistence concerns.
 */
final class TripCostBreakdown
{
    public function __construct(
        public readonly Money $grossFare,
        public readonly Money $platformCommission,
        public readonly Money $fuelCost,
        public readonly Money $depreciationCost,
        public readonly Money $otherCosts,
        public readonly Money $netProfit,
        public readonly Distance $distance,
        public readonly ?int $durationMinutes = null,
    ) {}

    /**
     * Total cost borne by the driver: commission + fuel + depreciation + extras.
     */
    public function totalCosts(): Money
    {
        return $this->platformCommission
            ->plus($this->fuelCost)
            ->plus($this->depreciationCost)
            ->plus($this->otherCosts);
    }

    /**
     * Net profit margin as a fraction of the gross fare.
     *
     * Returns null when the fare is zero, because a margin is undefined there.
     */
    public function margin(int $scale = 6): ?BigDecimal
    {
        if ($this->grossFare->isZero()) {
            return null;
        }

        return $this->netProfit->toDecimal()->dividedBy(
            $this->grossFare->toDecimal(),
            $scale,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * Net profit earned per kilometre driven.
     */
    public function profitPerKilometre(int $scale = 6): ?BigDecimal
    {
        if (! $this->distance->isPositive()) {
            return null;
        }

        return $this->netProfit->toDecimal()->dividedBy(
            $this->distance->toDecimal(),
            $scale,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * Net profit earned per minute connected.
     */
    public function profitPerMinute(int $scale = 6): ?BigDecimal
    {
        if ($this->durationMinutes === null || $this->durationMinutes <= 0) {
            return null;
        }

        return $this->netProfit->toDecimal()->dividedBy(
            (string) $this->durationMinutes,
            $scale,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * Fuel efficiency implied by this trip, i.e. distance divided by the
     * volume that the fuel cost could buy. Null when the ratio is undefined.
     */
    public function isProfitable(): bool
    {
        return $this->netProfit->isPositive();
    }

    public function breaksEven(): bool
    {
        // ``breakEvenFare()`` is solved on a 12-digit scale, so a break-even
        // fare can still leave sub-cent residue; anything that rounds to zero
        // at currency scale is a genuine break-even.
        return $this->netProfit->roundedTo($this->netProfit->currency->scale)->isZero();
    }
}
