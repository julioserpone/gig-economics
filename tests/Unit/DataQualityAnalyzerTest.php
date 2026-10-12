<?php

declare(strict_types=1);

use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Energy\EnergyPrice;
use GigEconomics\Domain\Energy\EnergyPurchase;
use GigEconomics\Domain\Quality\DataQualityAnalyzer;
use GigEconomics\Domain\Quality\DataQualityIssueType;
use GigEconomics\Domain\Quality\Severity;
use GigEconomics\Domain\Shared\EnergyQuantity;

function purchase(?int $odometer, string $gallons = '5'): EnergyPurchase
{
    return EnergyPurchase::create(EnergyQuantity::ofGallons($gallons), EnergyPrice::perGallon('4.00'), null, 'RUC', 'Vendor', $odometer);
}

it('flags records without a date as warnings', function (): void {
    $analyzer = new DataQualityAnalyzer(EnergyLog::fromPurchases([purchase(1000)]));

    $types = array_map(static fn ($issue): string => $issue->type->value, $analyzer->issues());

    expect($types)->toContain(DataQualityIssueType::MissingDate->value);
});

it('flags missing odometer readings as informational', function (): void {
    $analyzer = new DataQualityAnalyzer(EnergyLog::fromPurchases([purchase(null)]));

    expect($analyzer->issuesOf(Severity::Info))->not->toBeEmpty();
});

it('flags a zero energy amount as an error', function (): void {
    $analyzer = new DataQualityAnalyzer(EnergyLog::fromPurchases([purchase(1000, '0')]));

    expect($analyzer->hasErrors())->toBeTrue();
});

it('detects statistical outliers among economy readings', function (): void {
    $purchases = [];
    $odometer = 10000;

    // A tight cluster of ~50 km/gal plus one wild 500 km/gal reading.
    foreach ([500, 505, 495, 510, 498] as $distance) {
        $purchases[] = purchase($odometer, '10');
        $odometer += $distance;
    }

    $purchases[] = purchase($odometer, '1'); // 500 km on 1 gallon -> outlier
    $purchases[] = purchase($odometer + 500, '10');

    $analyzer = new DataQualityAnalyzer(EnergyLog::fromPurchases($purchases));

    expect($analyzer->outlierEconomies())->not->toBeEmpty();
});

it('scores a clean log higher than a dirty one', function (): void {
    $clean = new DataQualityAnalyzer(EnergyLog::fromPurchases([
        EnergyPurchase::create(
            EnergyQuantity::ofGallons('5'),
            EnergyPrice::perGallon('4.00'),
            new DateTimeImmutable('2026-04-01 10:00:00'),
            'RUC',
            'Vendor',
            1000,
        ),
    ]));

    $dirty = new DataQualityAnalyzer(EnergyLog::fromPurchases([purchase(null, '0')]));

    expect($clean->qualityScore()->toFloat())->toBeGreaterThan($dirty->qualityScore()->toFloat());
});
