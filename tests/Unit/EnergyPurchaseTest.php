<?php

declare(strict_types=1);

use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Energy\EnergyPrice;
use GigEconomics\Domain\Energy\EnergyPurchase;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Exceptions\InvalidDomainValueException;

it('reproduces the subtotal, tax and total of a refuelling event', function (): void {
    $purchase = EnergyPurchase::create(
        energy: EnergyQuantity::ofGallons('8.158'),
        unitPrice: EnergyPrice::perGallon('2.8783'),
        taxRate: Percentage::of(15),
    );

    expect($purchase->subtotal()->format(7, false))->toBe('23.4811714')
        ->and($purchase->tax()->format(7, false))->toBe('3.5221757')
        ->and($purchase->total()->format(8, false))->toBe('27.00334711');
});

it('derives the unit price from a subtotal and energy amount', function (): void {
    $price = EnergyPrice::fromSubtotal(
        Money::of('23.4811714'),
        EnergyQuantity::ofGallons('8.158'),
    );

    expect(round($price->toFloat(), 4))->toBe(2.8783);
});

it('prices a recharge per kilowatt-hour for an electric vehicle', function (): void {
    $purchase = EnergyPurchase::create(
        energy: EnergyQuantity::ofKilowattHours('40'),
        unitPrice: EnergyPrice::perKilowattHour('0.25'),
        energyType: EnergyType::Electric,
    );

    expect($purchase->total()->format(2, false))->toBe('10.00')
        ->and($purchase->isElectric())->toBeTrue();
});

it('computes per-purchase economy from consecutive odometer readings', function (): void {
    $log = EnergyLog::fromPurchases([
        EnergyPurchase::create(EnergyQuantity::ofGallons('7.187'), EnergyPrice::perGallon('2.9035'), null, 'RUC', 'A', 44619),
        EnergyPurchase::create(EnergyQuantity::ofGallons('8.02'), EnergyPrice::perGallon('3.9043'), null, 'RUC', 'A', 44950),
    ]);

    $economies = $log->economies();

    expect($economies)->toHaveCount(1)
        ->and(round($economies[0]->toFloat(), 3))->toBe(41.272);
});

it('computes per-purchase economy for an electric vehicle in km/kWh', function (): void {
    $log = EnergyLog::fromPurchases([
        EnergyPurchase::create(EnergyQuantity::ofKilowattHours('40'), EnergyPrice::perKilowattHour('0.25'), null, 'RUC', 'Charger', 1000, EnergyType::Electric),
        EnergyPurchase::create(EnergyQuantity::ofKilowattHours('40'), EnergyPrice::perKilowattHour('0.25'), null, 'RUC', 'Charger', 1240, EnergyType::Electric),
    ]);

    $economies = $log->economies();

    expect($economies)->toHaveCount(1)
        ->and($economies[0]->toFloat())->toBe(6.0)
        ->and($log->isElectric())->toBeTrue();
});

it('drops non-positive deltas when building economies', function (): void {
    $log = EnergyLog::fromPurchases([
        EnergyPurchase::create(EnergyQuantity::ofGallons('5'), EnergyPrice::perGallon('4.00'), null, 'RUC', 'A', 50000),
        EnergyPurchase::create(EnergyQuantity::ofGallons('5'), EnergyPrice::perGallon('4.00'), null, 'RUC', 'A', 50000),
    ]);

    expect($log->economies())->toHaveCount(0)
        ->and($log->totalDistance()->toFloat())->toBe(0.0);
});

it('keeps purchases without an odometer but derives no distance', function (): void {
    $log = EnergyLog::fromPurchases([
        EnergyPurchase::create(EnergyQuantity::ofGallons('6'), EnergyPrice::perGallon('4.00'), null, 'RUC', 'A', 50000),
        EnergyPurchase::create(EnergyQuantity::ofGallons('6'), EnergyPrice::perGallon('4.00')),
        EnergyPurchase::create(EnergyQuantity::ofGallons('6'), EnergyPrice::perGallon('4.00'), null, 'RUC', 'A', 50300),
    ]);

    expect($log->count())->toBe(3)
        ->and($log->totalDistance()->toFloat())->toBe(300.0);
});

it('totals energy, subtotal and spend across the log', function (): void {
    $log = EnergyLog::fromPurchases([
        EnergyPurchase::create(EnergyQuantity::ofGallons('8.158'), EnergyPrice::perGallon('2.8783'), null, 'RUC', 'A', null, null, Percentage::of(15)),
        EnergyPurchase::create(EnergyQuantity::ofGallons('7.187'), EnergyPrice::perGallon('2.9035'), null, 'RUC', 'A', null, null, Percentage::of(15)),
    ]);

    expect(round($log->totalEnergy()->toFloat(), 3))->toBe(15.345)
        ->and(round($log->totalSpend()->toFloat(), 4))->toBe(51.0009);
});

it('refuses to total energy across mismatched units', function (): void {
    $log = EnergyLog::fromPurchases([
        EnergyPurchase::create(EnergyQuantity::ofGallons('5'), EnergyPrice::perGallon('4.00')),
        EnergyPurchase::create(EnergyQuantity::ofKilowattHours('40'), EnergyPrice::perKilowattHour('0.25')),
    ]);

    expect($log->isElectric())->toBeFalse();

    $log->totalEnergy();
})->throws(InvalidDomainValueException::class);
