<?php

declare(strict_types=1);

use GigEconomics\Domain\Analytics\EnergyAnalytics;
use GigEconomics\Domain\Quality\DataQualityAnalyzer;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\TimeOfDay;
use GigEconomics\Support\EnergyLogImporter;

/**
 * Reproduces the KPIs documented for a realistic 84-row gasoline log
 * (April–October 2026) straight from the exported CSV.
 */
beforeEach(function (): void {
    $this->log = (new EnergyLogImporter)->fromFile($this->fixture('fuel_log.psv'));
    $this->analytics = new EnergyAnalytics($this->log);
});

it('reads every active refuelling row and skips the reserved capacity rows', function (): void {
    expect($this->log->count())->toBe(84);
});

it('matches the documented total energy and spend', function (): void {
    $kpis = $this->analytics->kpis();

    expect(round($kpis->totalEnergy->toFloat(), 2))->toBe(395.24)
        ->and(round($kpis->totalSpend->toFloat(), 4))->toBe(1912.3489)
        ->and($kpis->isElectric)->toBeFalse();
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

    expect($cheapest[0]->weightedPricePerUnit->isLessThanOrEqualTo($cheapest[1]->weightedPricePerUnit))->toBeTrue();
});

it('only ranks vendors with at least two refuels for economy', function (): void {
    foreach ($this->analytics->bestEconomyVendors() as $summary) {
        expect($summary->validEconomyCount)->toBeGreaterThanOrEqual(2);
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

it('analyses an electric charging log end to end', function (): void {
    $log = (new EnergyLogImporter)->fromFile(
        $this->fixture('electric_log.psv'),
        0.0,
        EnergyType::Electric,
    );
    $analytics = new EnergyAnalytics($log);
    $kpis = $analytics->kpis();

    expect($log->count())->toBe(4)
        ->and($kpis->isElectric)->toBeTrue()
        ->and($kpis->totalEnergy->unit)->toBe(EnergyQuantity::ofKilowattHours('1')->unit)
        ->and(round($kpis->totalEnergy->toFloat(), 2))->toBe(165.50)
        ->and(round($analytics->weightedPrice()->toFloat(), 6))->toBe(0.281903)
        ->and(round($kpis->averageEconomy?->toFloat() ?? 0, 6))->toBe(6.808480)
        ->and($analytics->vendorRanking())->toHaveCount(2);
});

it('derives electric energy cost per kilometre from the weighted price', function (): void {
    $log = (new EnergyLogImporter)->fromFile(
        $this->fixture('electric_log.psv'),
        0.0,
        EnergyType::Electric,
    );
    $analytics = new EnergyAnalytics($log);

    $costPerKm = $analytics->costPerKilometre();

    expect($costPerKm)->not->toBeNull()
        ->and(round($costPerKm?->toFloat() ?? 0, 4))->toBe(0.0555);
});

it('keeps the combustion and electric economies comparable through the shared type', function (): void {
    $petrol = EnergyEconomy::fromDistanceAndEnergy(Distance::ofKilometres(300), EnergyQuantity::ofGallons('10'));
    $electric = EnergyEconomy::fromDistanceAndEnergy(Distance::ofKilometres(300), EnergyQuantity::ofKilowattHours('50'));

    expect($petrol->isUsable())->toBeTrue()
        ->and($electric->isUsable())->toBeTrue()
        ->and($petrol->unit->isElectric())->toBeFalse()
        ->and($electric->unit->isElectric())->toBeTrue();
});
