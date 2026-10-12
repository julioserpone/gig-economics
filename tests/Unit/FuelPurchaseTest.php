<?php

declare(strict_types=1);

use GigEconomics\Domain\Fuel\FuelLog;
use GigEconomics\Domain\Fuel\FuelPrice;
use GigEconomics\Domain\Fuel\FuelPurchase;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\FuelVolume;

it('reproduces the calculated columns of a workbook row', function (): void {
    // Row 2026-04-05: 8.158 gal @ 2.8783 unit value, 15% IVA
    $purchase = FuelPurchase::create(
        volume: FuelVolume::ofGallons('8.158'),
        unitPrice: FuelPrice::perGallon('2.8783'),
        taxRate: Percentage::of(15),
    );

    expect($purchase->subtotal()->format(7, false))->toBe('23.4811714')
        ->and($purchase->tax()->format(7, false))->toBe('3.5221757')
        ->and($purchase->total()->format(8, false))->toBe('27.00334711');
});

it('derives the unit price from a subtotal and volume', function (): void {
    $price = FuelPrice::fromSubtotal(
        Money::of('23.4811714'),
        FuelVolume::ofGallons('8.158'),
    );

    expect(round($price->toFloat(), 4))->toBe(2.8783);
});

it('computes per-purchase efficiency from consecutive odometer readings', function (): void {
    $log = FuelLog::fromPurchases([
        FuelPurchase::create(FuelVolume::ofGallons('7.187'), FuelPrice::perGallon('2.9035'), null, 'RUC', 'A', 44619),
        FuelPurchase::create(FuelVolume::ofGallons('8.02'), FuelPrice::perGallon('3.9043'), null, 'RUC', 'A', 44950),
    ]);

    $efficiencies = $log->efficiencies();

    expect($efficiencies)->toHaveCount(1)
        ->and(round($efficiencies[0]->toFloat(), 3))->toBe(41.272);
});

it('drops non-positive deltas when building efficiencies', function (): void {
    // Two refuels sharing the same odometer reading produce a zero delta,
    // which must not become a zero km/gal efficiency reading.
    $log = FuelLog::fromPurchases([
        FuelPurchase::create(FuelVolume::ofGallons('5'), FuelPrice::perGallon('4.00'), null, 'RUC', 'A', 50000),
        FuelPurchase::create(FuelVolume::ofGallons('5'), FuelPrice::perGallon('4.00'), null, 'RUC', 'A', 50000),
    ]);

    expect($log->efficiencies())->toHaveCount(0)
        ->and($log->totalDistance()->toFloat())->toBe(0.0);
});

it('keeps purchases without an odometer but derives no distance', function (): void {
    $log = FuelLog::fromPurchases([
        FuelPurchase::create(FuelVolume::ofGallons('6'), FuelPrice::perGallon('4.00'), null, 'RUC', 'A', 50000),
        FuelPurchase::create(FuelVolume::ofGallons('6'), FuelPrice::perGallon('4.00')),
        FuelPurchase::create(FuelVolume::ofGallons('6'), FuelPrice::perGallon('4.00'), null, 'RUC', 'A', 50300),
    ]);

    expect($log->count())->toBe(3)
        ->and($log->totalDistance()->toFloat())->toBe(300.0);
});

it('totals volume, subtotal and spend across the log', function (): void {
    $log = FuelLog::fromPurchases([
        FuelPurchase::create(FuelVolume::ofGallons('8.158'), FuelPrice::perGallon('2.8783'), null, 'RUC', 'A', null, null, Percentage::of(15)),
        FuelPurchase::create(FuelVolume::ofGallons('7.187'), FuelPrice::perGallon('2.9035'), null, 'RUC', 'A', null, null, Percentage::of(15)),
    ]);

    expect(round($log->totalVolume()->toFloat(), 3))->toBe(15.345)
        ->and(round($log->totalSpend()->toFloat(), 4))->toBe(51.0009);
});
