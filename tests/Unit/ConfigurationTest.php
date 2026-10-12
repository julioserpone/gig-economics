<?php

declare(strict_types=1);

use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;
use GigEconomics\Support\Configuration;
use GigEconomics\Support\EnergyLogImporter;
use GigEconomics\Support\TripProfitabilityFactory;

it('exposes the built-in defaults without touching the environment', function (): void {
    $configuration = Configuration::defaults();

    expect($configuration->defaultTaxRatePercent)->toBe(15.0)
        ->and($configuration->defaultEnergyType)->toBe(EnergyType::RegularGasoline)
        ->and($configuration->defaultTargetProfitPerKilometre)->toBe('0.15');
});

it('builds from an explicit array and ignores unknown keys', function (): void {
    $configuration = Configuration::fromArray([
        'tax_rate_percent' => 21,
        'default_energy_type' => 'electric',
        'target_profit_per_kilometre' => '0.20',
        'unknown_future_key' => 'ignored',
    ]);

    expect($configuration->defaultTaxRatePercent)->toBe(21.0)
        ->and($configuration->defaultEnergyType)->toBe(EnergyType::Electric)
        ->and($configuration->defaultTargetProfitPerKilometre)->toBe('0.20');
});

it('falls back to the defaults for missing or invalid array values', function (): void {
    $configuration = Configuration::fromArray([
        'tax_rate_percent' => 'not-a-number',
        'default_energy_type' => 'unobtainium',
    ]);

    expect($configuration->defaultTaxRatePercent)->toBe(15.0)
        ->and($configuration->defaultEnergyType)->toBe(EnergyType::RegularGasoline);
});

it('returns a new instance from each with method and never mutates the original', function (): void {
    $original = Configuration::defaults();
    $updated = $original->withTaxRatePercent(7.5)->withDefaultEnergyType(EnergyType::Electric);

    expect($original->defaultTaxRatePercent)->toBe(15.0)
        ->and($original->defaultEnergyType)->toBe(EnergyType::RegularGasoline)
        ->and($updated->defaultTaxRatePercent)->toBe(7.5)
        ->and($updated->defaultEnergyType)->toBe(EnergyType::Electric);
});

it('reads overrides from the process environment', function (): void {
    $names = [
        Configuration::ENV_TAX_RATE,
        Configuration::ENV_DEFAULT_ENERGY_TYPE,
        Configuration::ENV_TARGET_PROFIT_PER_KILOMETRE,
    ];

    $previous = [];

    foreach ($names as $name) {
        $previous[$name] = $_ENV[$name] ?? null;
    }

    $_ENV[Configuration::ENV_TAX_RATE] = '8.25';
    $_ENV[Configuration::ENV_DEFAULT_ENERGY_TYPE] = 'electric';
    $_ENV[Configuration::ENV_TARGET_PROFIT_PER_KILOMETRE] = '0.30';

    try {
        $configuration = Configuration::fromEnvironment();

        expect($configuration->defaultTaxRatePercent)->toBe(8.25)
            ->and($configuration->defaultEnergyType)->toBe(EnergyType::Electric)
            ->and($configuration->defaultTargetProfitPerKilometre)->toBe('0.30');
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }
});

it('uses the configured tax rate in the importer by default', function (): void {
    $psv = '"date"|"energy"|"unit_price"'."\n"
        .'"2026-04-05"|"8.158"|"2.8783"';

    $configured = new EnergyLogImporter(Configuration::defaults()->withTaxRatePercent(0.0));
    $default = new EnergyLogImporter(Configuration::defaults());

    $withoutTax = $configured->fromString($psv)->all()[0]->total();
    $withTax = $default->fromString($psv)->all()[0]->total();

    expect(round($withoutTax->toFloat(), 4))->toBe(23.4812)
        ->and(round($withTax->toFloat(), 4))->toBe(27.0033);
});

it('lets an explicit call argument override the configured default', function (): void {
    $importer = new EnergyLogImporter(Configuration::defaults()->withTaxRatePercent(0.0));
    $psv = '"date"|"energy"|"unit_price"'."\n"
        .'"2026-04-05"|"8.158"|"2.8783"';

    $log = $importer->fromString($psv, 15.0);

    expect(round($log->all()[0]->total()->toFloat(), 4))->toBe(27.0033);
});

it('builds a calculator whose target comes from the configuration', function (): void {
    $factory = TripProfitabilityFactory::fromConfiguration(
        Configuration::defaults()->withTargetProfitPerKilometre('0.25'),
    );

    $calculator = $factory->calculator();

    expect($calculator)->toBeInstanceOf(TripProfitabilityCalculator::class);
});
