<?php

declare(strict_types=1);

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\Platform;
use GigEconomics\Domain\Trip\Fare;
use GigEconomics\Domain\Trip\TripCostProfile;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;
use GigEconomics\Domain\Trip\TripVerdict;

function profile(): TripCostProfile
{
    return TripCostProfile::forCombustion(
        pricePerGallon: Money::of('4.8384'),
        economy: EnergyEconomy::ofKilometresPerGallon('53.32'),
        depreciationPerKilometre: Money::of('0.15'),
        commissionRate: Percentage::of(25),
    );
}

function electricProfile(): TripCostProfile
{
    return TripCostProfile::forElectric(
        pricePerKilowattHour: Money::of('0.30'),
        economy: EnergyEconomy::ofKilometresPerKilowattHour('6.00'),
        depreciationPerKilometre: Money::of('0.10'),
        commissionRate: Percentage::of(25),
    );
}

it('computes net profit after commission, fuel and depreciation', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'));
    $fare = Fare::create(Money::of('20.00'), Distance::ofKilometres(10));

    $result = $calculator->calculate($fare, profile(), Platform::Uber);

    // commission 5.00 | energy = 10km / 53.32 km/gal * 4.8384 = 0.9074 | depreciation 1.50
    expect($result->breakdown->platformCommission->format(2, false))->toBe('5.00')
        ->and(round($result->breakdown->depreciationCost->toFloat(), 2))->toBe(1.50)
        ->and(round($result->breakdown->energyCost->toFloat(), 2))->toBe(0.91)
        ->and(round($result->netProfit()->toFloat(), 2))->toBe(12.59)
        ->and($result->isProfitable())->toBeTrue()
        ->and($result->verdict())->toBe(TripVerdict::Accept);
});

it('computes net profit for an electric vehicle paid per kilowatt-hour', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'));
    $fare = Fare::create(Money::of('20.00'), Distance::ofKilometres(12));

    $result = $calculator->calculate($fare, electricProfile(), Platform::Uber);

    // commission 5.00 | energy = 12km / 6 km/kWh * 0.30 = 0.60 | depreciation 1.20
    expect(round($result->breakdown->energyCost->toFloat(), 2))->toBe(0.60)
        ->and(round($result->breakdown->depreciationCost->toFloat(), 2))->toBe(1.20)
        ->and(round($result->netProfit()->toFloat(), 2))->toBe(13.20)
        ->and($result->isProfitable())->toBeTrue();
});

it('rejects an unprofitable offer', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.15'));
    $fare = Fare::create(Money::of('1.50'), Distance::ofKilometres(10));

    $result = $calculator->calculate($fare, profile(), Platform::Uber);

    expect($result->isProfitable())->toBeFalse()
        ->and($result->verdict())->toBe(TripVerdict::Reject)
        ->and($result->verdict()->isWorthTaking())->toBeFalse();
});

it('accounts for deadhead distance the driver must drive unpaid', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'));
    $fare = Fare::create(Money::of('20.00'), Distance::ofKilometres(10));

    $direct = $calculator->calculate($fare, profile());
    $withDeadhead = $calculator->calculate($fare, profile(), deadheadDistance: Distance::ofKilometres(5));

    expect($withDeadhead->netProfit()->isLessThan($direct->netProfit()))->toBeTrue()
        ->and(round($withDeadhead->distance()->toFloat(), 2))->toBe(15.0);
});

it('solves the break even fare so net profit is zero', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'));
    $distance = Distance::ofKilometres(20);
    $breakEven = $calculator->breakEvenFare(profile(), $distance);

    $fare = Fare::create($breakEven, $distance);
    $result = $calculator->calculate($fare, profile(), Platform::Uber);

    expect(round($result->netProfit()->toFloat(), 2))->toBe(0.00)
        ->and($result->breakdown->breaksEven())->toBeTrue();
});

it('derives energy cost per kilometre from a weighted price and economy', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget();

    $costPerKm = $calculator->energyCostPerKilometreFrom(
        Money::of('4.8384'),
        EnergyEconomy::ofKilometresPerGallon('53.32'),
    );

    expect(round($costPerKm->toFloat(), 4))->toBe(0.0907);
});

it('derives energy cost per kilometre for an electric vehicle', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget();

    $costPerKm = $calculator->energyCostPerKilometreFrom(
        Money::of('0.30'),
        EnergyEconomy::ofKilometresPerKilowattHour('6'),
    );

    expect(round($costPerKm->toFloat(), 4))->toBe(0.0500);
});

it('returns marginal for an offer close to the target', function (): void {
    $verdict = TripVerdict::fromProfitPerKilometre(0.145, 0.15, 0.10);

    expect($verdict)->toBe(TripVerdict::Marginal)
        ->and($verdict->isWorthTaking())->toBeTrue();
});

it('never treats an unusable economy as free energy', function (): void {
    $broken = TripCostProfile::forCombustion(
        pricePerGallon: Money::of('4.8384'),
        economy: EnergyEconomy::ofKilometresPerGallon('0'),
        depreciationPerKilometre: Money::of('0.15'),
        commissionRate: Percentage::of(25),
    );

    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'));
    $result = $calculator->calculate(Fare::create(Money::of('20.00'), Distance::ofKilometres(10)), $broken);

    expect($result->breakdown->energyCost->isZero())->toBeTrue();
});

it('exposes profit per kilometre and per minute on the breakdown', function (): void {
    $calculator = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'));
    $fare = Fare::create(Money::of('20.00'), Distance::ofKilometres(10), 20);

    $breakdown = $calculator->calculate($fare, profile(), Platform::Uber)->breakdown;

    expect(round($breakdown->profitPerKilometre()?->toFloat() ?? 0, 2))->toBe(1.26)
        ->and(round($breakdown->profitPerMinute()?->toFloat() ?? 0, 2))->toBe(0.63);
});
