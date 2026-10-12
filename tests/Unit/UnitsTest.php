<?php

declare(strict_types=1);

use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\FuelVolume;
use GigEconomics\Exceptions\InvalidDomainValueException;

it('converts miles to kilometres', function (): void {
    expect(Distance::ofMiles('1')->round(4)->toFloat())->toBe(1.6093);
});

it('derives travelled distance from two odometer readings', function (): void {
    // Workbook row 2026-04-08: 44950 -> 4495 is a corrupted reading and yields no distance.
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
    expect(FuelVolume::ofLitres('3.785411784')->toFloat())->toBe(1.0);
});

it('computes km per gallon from distance and volume', function (): void {
    // Workbook row 2026-04-08: 331 km over 7.187 gal = 46.055...
    $efficiency = FuelEfficiency::fromDistanceAndVolume(
        Distance::ofKilometres(331),
        FuelVolume::ofGallons('7.187'),
    );

    expect(round($efficiency->toFloat(), 3))->toBe(46.055);
});

it('treats zero and negative efficiency as unusable', function (): void {
    expect(FuelEfficiency::ofKilometresPerGallon('0')->isUsable())->toBeFalse()
        ->and(FuelEfficiency::ofKilometresPerGallon('-3')->isUsable())->toBeFalse()
        ->and(FuelEfficiency::ofKilometresPerGallon('12')->isUsable())->toBeTrue();
});

it('converts efficiency between metric and imperial units', function (): void {
    // 1 km/L == 3.785411784 km/gal
    $metric = FuelEfficiency::ofKilometresPerLitre('1');

    expect(round($metric->kilometresPerGallon()->toFloat(), 6))->toBe(3.785412);
});

it('computes litres per 100 km from km per gallon', function (): void {
    $efficiency = FuelEfficiency::ofKilometresPerGallon('10');

    // 10 km/gal -> 100 km needs 10 gal -> 37.85411784 L
    expect(round($efficiency->litresPer100Kilometres()->toFloat(), 4))->toBe(37.8541);
});
