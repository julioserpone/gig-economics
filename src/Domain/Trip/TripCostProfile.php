<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use Brick\Math\RoundingMode;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;

/**
 * The operating assumptions of a driver/vehicle used to price a trip.
 *
 * This is the reusable "profile" a dashboard or mobile app keeps in memory:
 * the price per unit of energy, the real economy, the depreciation rate and the
 * platform commission. The same profile serves a combustion vehicle (energy
 * priced per gallon) and an electric one (priced per kilowatt-hour), because
 * the energy unit travels with the economy reading it was built from.
 */
final class TripCostProfile
{
    public function __construct(
        public readonly Money $energyPricePerUnit,
        public readonly EnergyEconomy $energyEconomy,
        public readonly Money $depreciationPerKilometre,
        public readonly Percentage $commissionRate,
        public readonly Money $fixedCostPerTrip,
    ) {}

    public static function create(
        Money $energyPricePerUnit,
        EnergyEconomy $energyEconomy,
        ?Money $depreciationPerKilometre = null,
        ?Percentage $commissionRate = null,
        ?Money $fixedCostPerTrip = null,
    ): self {
        $currency = $energyPricePerUnit->currency;

        return new self(
            $energyPricePerUnit,
            $energyEconomy,
            $depreciationPerKilometre ?? Money::zero($currency),
            $commissionRate ?? Percentage::zero(),
            $fixedCostPerTrip ?? Money::zero($currency),
        );
    }

    /**
     * Convenience factory for a combustion vehicle, whose energy is priced per
     * gallon.
     */
    public static function forCombustion(
        Money $pricePerGallon,
        EnergyEconomy $economy,
        ?Money $depreciationPerKilometre = null,
        ?Percentage $commissionRate = null,
        ?Money $fixedCostPerTrip = null,
    ): self {
        return self::create(
            $pricePerGallon,
            $economy,
            $depreciationPerKilometre,
            $commissionRate,
            $fixedCostPerTrip,
        );
    }

    /**
     * Convenience factory for an electric vehicle, whose energy is priced per
     * kilowatt-hour.
     */
    public static function forElectric(
        Money $pricePerKilowattHour,
        EnergyEconomy $economy,
        ?Money $depreciationPerKilometre = null,
        ?Percentage $commissionRate = null,
        ?Money $fixedCostPerTrip = null,
    ): self {
        return self::create(
            $pricePerKilowattHour,
            $economy,
            $depreciationPerKilometre,
            $commissionRate,
            $fixedCostPerTrip,
        );
    }

    public function currency(): Currency
    {
        return $this->energyPricePerUnit->currency;
    }

    /**
     * Whether the profile describes an electric vehicle.
     */
    public function isElectric(): bool
    {
        return $this->energyEconomy->unit->isElectric();
    }

    /**
     * Energy cost of driving a given distance at the profile's economy.
     */
    public function energyCostFor(Distance $distance): Money
    {
        if (! $this->energyEconomy->isUsable()) {
            return Money::zero($this->currency());
        }

        $units = $distance->toDecimal()->dividedBy(
            $this->energyEconomy->kilometresPerUnit(),
            9,
            RoundingMode::HALF_UP,
        );

        return $this->energyPricePerUnit->multipliedBy($units, 6);
    }

    /**
     * Depreciation cost of driving a given distance.
     */
    public function depreciationFor(Distance $distance): Money
    {
        return $this->depreciationPerKilometre->multipliedBy($distance->toDecimal(), 6);
    }
}
