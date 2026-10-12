<?php

declare(strict_types=1);

use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyUnit;
use GigEconomics\Exceptions\InvalidDomainValueException;

it('converts miles to kilometres', function (): void {
    expect(Distance::ofMiles('1')->round(4)->toFloat())->toBe(1.6093);
});

it('derives travelled distance from two odometer readings', function (): void {
    // A reading that jumps backwards (44950 -> 4495) is corrupted and yields no distance.
    $delta = Distance::betweenReadings(44619, 44950);

    expect($delta->toFloat())->toBe(331.0)
        ->and($delta->isPositive())->toBeTrue();
});

it('flags a backwards odometer reading as non-positive', function (): void {
    expect(Distance::betweenReadings(44950, 4495)->isPositive())->toBeFalse();
});

it('refuses a negative distance', function (): void {
    Distance::ofKilometres('-1');
})->throws(InvalidDomainValueException::class);

it('converts litres to gallons', function (): void {
    expect(EnergyQuantity::ofLitres('3.785411784')->gallons()->toFloat())->toBe(1.0);
});

it('builds a kilowatt-hour quantity for an electric vehicle', function (): void {
    $energy = EnergyQuantity::ofKilowattHours('42.5');

    expect($energy->toFloat())->toBe(42.5)
        ->and($energy->unit)->toBe(EnergyUnit::KilowattHours)
        ->and($energy->isElectric())->toBeTrue();
});

it('computes km per gallon from distance and energy', function (): void {
    $economy = EnergyEconomy::fromDistanceAndEnergy(
        Distance::ofKilometres(331),
        EnergyQuantity::ofGallons('7.187'),
    );

    expect(round($economy->toFloat(), 3))->toBe(46.055)
        ->and($economy->unit)->toBe(EnergyUnit::Gallons);
});

it('computes km per kilowatt-hour from distance and energy', function (): void {
    $economy = EnergyEconomy::fromDistanceAndEnergy(
        Distance::ofKilometres(300),
        EnergyQuantity::ofKilowattHours('50'),
    );

    expect($economy->toFloat())->toBe(6.0)
        ->and($economy->unit)->toBe(EnergyUnit::KilowattHours)
        ->and(round($economy->kilowattHoursPer100Kilometres()->toFloat(), 4))->toBe(16.6667);
});

it('treats zero and negative economy as unusable', function (): void {
    expect(EnergyEconomy::ofKilometresPerGallon('0')->isUsable())->toBeFalse()
        ->and(EnergyEconomy::ofKilometresPerGallon('-3')->isUsable())->toBeFalse()
        ->and(EnergyEconomy::ofKilometresPerGallon('12')->isUsable())->toBeTrue();
});

it('converts economy between metric and imperial units', function (): void {
    // 1 km/L == 3.785411784 km/gal
    $metric = EnergyEconomy::ofKilometresPerLitre('1');

    expect(round($metric->kilometresPerGallon()->toFloat(), 6))->toBe(3.785412);
});

it('computes litres per 100 km from km per gallon', function (): void {
    $economy = EnergyEconomy::ofKilometresPerGallon('10');

    expect(round($economy->litresPer100Kilometres()->toFloat(), 4))->toBe(37.8541);
});

it('refuses to read a gallon figure from an electric economy', function (): void {
    EnergyEconomy::ofKilometresPerKilowattHour('6')->kilometresPerGallon();
})->throws(InvalidDomainValueException::class);
