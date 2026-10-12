<?php

declare(strict_types=1);

use GigEconomics\Domain\Analytics\FuelAnalytics;
use GigEconomics\Domain\Quality\DataQualityAnalyzer;
use GigEconomics\Domain\Shared\TimeOfDay;
use GigEconomics\Support\FuelLogCsvImporter;

/**
 * Reproduces the KPIs documented for `TblGasolina` (84 active rows,
 * April–October 2026) straight from the exported CSV.
 */
beforeEach(function (): void {
    $this->log = (new FuelLogCsvImporter)->fromFile($this->fixture('tblGasolina.csv'));
    $this->analytics = new FuelAnalytics($this->log);
});

it('reads every active refuelling row and skips the reserved capacity rows', function (): void {
    expect($this->log->count())->toBe(84);
});

it('matches the documented total volume and spend', function (): void {
    $kpis = $this->analytics->kpis();

    expect(round($kpis->totalVolume->toFloat(), 2))->toBe(395.24)
        ->and(round($kpis->totalSpend->toFloat(), 4))->toBe(1912.3489);
});

it('matches the documented weighted price', function (): void {
    expect(round($this->analytics->weightedPrice()->toFloat(), 6))->toBe(4.838450);
});

it('matches the documented vendor count', function (): void {
    expect($this->analytics->kpis()->vendorCount)->toBe(13);
});

it('matches the documented top-2 spend concentration', function (): void {
    expect(round($this->analytics->spendConcentration(2)->toFloat(), 6))->toBe(0.826383);
});

it('produces a monthly series covering the documented period', function (): void {
    $series = $this->analytics->monthlySeries();
    $periods = array_map(static fn ($summary): string => $summary->period, $series);

    expect($periods)->toContain('2026-04')
        ->and($periods)->toContain('2026-10')
        ->and($periods[0])->toBe('2026-04')
        ->and(end($periods))->toBe('2026-10');
});

it('ranks the vendors by spend, highest first', function (): void {
    $ranking = $this->analytics->vendorRanking();

    expect($ranking)->toHaveCount(10)
        ->and($ranking[0]->totalSpend->isGreaterThanOrEqualTo($ranking[1]->totalSpend))->toBeTrue();
});

it('finds the cheapest vendors by weighted price', function (): void {
    $cheapest = $this->analytics->cheapestVendors(3);

    expect($cheapest[0]->weightedPricePerGallon->isLessThanOrEqualTo($cheapest[1]->weightedPricePerGallon))->toBeTrue();
});

it('only ranks vendors with at least two refuels for efficiency', function (): void {
    foreach ($this->analytics->bestEfficiencyVendors() as $summary) {
        expect($summary->validEfficiencyCount)->toBeGreaterThanOrEqual(2);
    }
});

it('splits spend across the four time-of-day bands', function (): void {
    $bands = $this->analytics->timeOfDayBreakdown();

    expect($bands)->toHaveCount(4)
        ->and($bands[0]->band)->toBe(TimeOfDay::Dawn)
        ->and($bands[3]->band)->toBe(TimeOfDay::Night);
});

it('reports a data quality score for the realistic fixture', function (): void {
    $analyzer = new DataQualityAnalyzer($this->log);

    expect($analyzer->qualityScore()->toFloat())->toBeGreaterThan(0.0)
        ->and($analyzer->qualityScore()->toFloat())->toBeLessThanOrEqual(1.0);
});

it('flags the corrupted odometer readings in the fixture', function (): void {
    $analyzer = new DataQualityAnalyzer($this->log);

    expect($analyzer->issues())->not->toBeEmpty();
});
