<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use Brick\Math\RoundingMode;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;

/**
 * The operating assumptions of a driver/vehicle used to price a trip.
 *
 * This is the reusable "profile" a dashboard or mobile app keeps in memory:
 * fuel price, real efficiency, depreciation rate and commission.
 */
final class TripCostProfile
{
    public function __construct(
        public readonly Money $fuelPricePerGallon,
        public readonly FuelEfficiency $fuelEfficiency,
        public readonly Money $depreciationPerKilometre,
        public readonly Percentage $commissionRate,
        public readonly Money $fixedCostPerTrip,
    ) {}

    public static function create(
        Money $fuelPricePerGallon,
        FuelEfficiency $fuelEfficiency,
        ?Money $depreciationPerKilometre = null,
        ?Percentage $commissionRate = null,
        ?Money $fixedCostPerTrip = null,
    ): self {
        $currency = $fuelPricePerGallon->currency;

        return new self(
            $fuelPricePerGallon,
            $fuelEfficiency,
            $depreciationPerKilometre ?? Money::zero($currency),
            $commissionRate ?? Percentage::zero(),
            $fixedCostPerTrip ?? Money::zero($currency),
        );
    }

    public function currency(): Currency
    {
        return $this->fuelPricePerGallon->currency;
    }

    /**
     * Fuel cost of driving a given distance at the profile's efficiency.
     */
    public function fuelCostFor(Distance $distance): Money
    {
        if (! $this->fuelEfficiency->isUsable()) {
            return Money::zero($this->currency());
        }

        $gallons = $distance->toDecimal()->dividedBy(
            $this->fuelEfficiency->kilometresPerGallon(),
            9,
            RoundingMode::HALF_UP,
        );

        return $this->fuelPricePerGallon->multipliedBy($gallons, 6);
    }

    /**
     * Depreciation cost of driving a given distance.
     */
    public function depreciationFor(Distance $distance): Money
    {
        return $this->depreciationPerKilometre->multipliedBy($distance->toDecimal(), 6);
    }
}
