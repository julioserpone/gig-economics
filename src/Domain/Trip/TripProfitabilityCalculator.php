<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\Platform;

/**
 * Pure calculation engine for the profitability of a single trip.
 *
 * The engine is stateless and platform-agnostic: it never talks to Uber, DiDi,
 * inDrive or Lyft, it only consumes the numbers they expose to the driver.
 */
final class TripProfitabilityCalculator
{
    public function __construct(
        private readonly Money $defaultTargetProfitPerKilometre,
    ) {}

    public static function withDefaultTarget(?Money $target = null): self
    {
        return new self($target ?? Money::of('0.15'));
    }

    /**
     * Assess a trip offer against a cost profile.
     */
    public function calculate(
        Fare $fare,
        TripCostProfile $profile,
        Platform $platform = Platform::Custom,
        ?Distance $deadheadDistance = null,
    ): TripProfitability {
        $currency = $fare->grossAmount->currency;
        $billableDistance = $fare->distance;
        $totalDistance = $deadheadDistance === null
            ? $billableDistance
            : $billableDistance->plus($deadheadDistance);

        $commission = $fare->commission($profile->commissionRate);
        $energyCost = $profile->energyCostFor($totalDistance);
        $depreciation = $profile->depreciationFor($totalDistance);
        $otherCosts = $profile->fixedCostPerTrip;

        $netProfit = $fare->grossAmount
            ->minus($commission)
            ->minus($energyCost)
            ->minus($depreciation)
            ->minus($otherCosts);

        $breakdown = new TripCostBreakdown(
            grossFare: $fare->grossAmount,
            platformCommission: $commission,
            energyCost: $energyCost,
            depreciationCost: $depreciation,
            otherCosts: $otherCosts,
            netProfit: $netProfit,
            distance: $totalDistance,
            durationMinutes: $fare->durationMinutes,
        );

        $targetPerKilometre = $this->targetPerKilometre($profile);
        $actualPerKilometre = $netProfit->toDecimal()->dividedBy(
            $totalDistance->isPositive() ? $totalDistance->toDecimal() : BigDecimal::of('1'),
            6,
            BrickRoundingMode::HALF_UP,
        );

        $verdict = TripVerdict::fromProfitPerKilometre(
            $actualPerKilometre->toFloat(),
            $targetPerKilometre->toFloat(),
        );

        return new TripProfitability(
            platform: $platform,
            breakdown: $breakdown,
            verdict: $verdict,
            breakEvenFare: $this->breakEvenFare($profile, $totalDistance, $currency),
        );
    }

    /**
     * Fare required so that `netProfit === 0` for a given distance.
     */
    public function breakEvenFare(
        TripCostProfile $profile,
        Distance $distance,
        ?Currency $currency = null,
    ): Money {
        $currency ??= $profile->currency();

        $costs = $profile->energyCostFor($distance)
            ->plus($profile->depreciationFor($distance))
            ->plus($profile->fixedCostPerTrip);

        $commissionFraction = $profile->commissionRate->asFraction();

        if ($commissionFraction->isGreaterThanOrEqualTo('1')) {
            return Money::of('0', $currency);
        }

        $divisor = BigDecimal::of('1')->minus($commissionFraction);

        return $costs->dividedBy($divisor, 12);
    }

    /**
     * Minimum fare the driver should accept per kilometre.
     */
    private function targetPerKilometre(TripCostProfile $profile): Money
    {
        if (! $this->defaultTargetProfitPerKilometre->isZero()) {
            return $this->defaultTargetProfitPerKilometre;
        }

        return Money::of('0.15', $profile->currency());
    }

    /**
     * Effective energy cost per kilometre implied by a profile.
     */
    public function energyCostPerKilometre(TripCostProfile $profile): Money
    {
        return $profile->energyCostFor(Distance::ofKilometres(1));
    }

    /**
     * Effective energy cost expressed as money per kilometre, given a weighted
     * energy price and a real economy reading. The price and the economy must
     * share a unit (per gallon, per litre or per kWh).
     */
    public function energyCostPerKilometreFrom(Money $weightedPricePerUnit, EnergyEconomy $economy): Money
    {
        if (! $economy->isUsable()) {
            return Money::zero($weightedPricePerUnit->currency);
        }

        return $weightedPricePerUnit->dividedBy($economy->kilometresPerUnit(), 6);
    }

    /**
     * Commission amount for an arbitrary gross fare and rate.
     */
    public function commissionFor(Money $grossFare, Percentage $rate): Money
    {
        return $grossFare->percentage($rate);
    }
}
