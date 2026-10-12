# Gig Economics

[![Tests](https://github.com/julioserpone/gig-economics/actions/workflows/tests.yml/badge.svg)](https://github.com/julioserpone/gig-economics/actions/workflows/tests.yml)
[![Code Quality](https://github.com/julioserpone/gig-economics/actions/workflows/code-quality.yml/badge.svg)](https://github.com/julioserpone/gig-economics/actions/workflows/code-quality.yml)
[![Latest Version](https://img.shields.io/packagist/v/julioserpone/gig-economics.svg)](https://packagist.org/packages/julioserpone/gig-economics)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A decoupled, platform-agnostic PHP library that answers the only question a
ride-hailing driver really cares about: **did this trip make money?**

It computes energy economy, operating costs and the real profitability of a
trip taken on **Uber, DiDi, inDrive, Lyft** — or any other marketplace — for
**combustion and electric vehicles alike**, without talking to any platform,
without a database, and without ever losing a cent to floating-point arithmetic.

```php
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\Platform;
use GigEconomics\Domain\Trip\Fare;
use GigEconomics\Domain\Trip\TripCostProfile;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;

$profile = TripCostProfile::forCombustion(
    pricePerGallon: Money::of('4.8384'),
    economy: EnergyEconomy::ofKilometresPerGallon('53.32'),
    depreciationPerKilometre: Money::of('0.15'),
    commissionRate: Percentage::of(25),
);

$result = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'))
    ->calculate(
        Fare::create(Money::of('20.00'), Distance::ofKilometres(10), durationMinutes: 20),
        $profile,
        Platform::Uber,
    );

$result->netProfit()->format(); // $12.59
$result->verdict()->label();    // Accept
```

### The same call for an electric vehicle

```php
$electric = TripCostProfile::forElectric(
    pricePerKilowattHour: Money::of('0.30'),
    economy: EnergyEconomy::ofKilometresPerKilowattHour('6.00'),
    depreciationPerKilometre: Money::of('0.10'),
    commissionRate: Percentage::of(25),
);

$result = TripProfitabilityCalculator::withDefaultTarget(Money::of('0.10'))
    ->calculate(
        Fare::create(Money::of('20.00'), Distance::ofKilometres(12)),
        $electric,
        Platform::Uber,
    );

$result->breakdown->energyCost; // cost of the electricity consumed
```

## Why this library exists

Drivers see a **gross fare**. What actually reaches their pocket is that fare
minus the platform commission, minus the energy consumed, minus the kilometres
added to the car. Gig Economics turns the gross number into the net one, and
turns the decision of *"should I accept this trip?"* into a single call.

The design is deliberately **stateless and storage-free**: you pass data in,
you get numbers out. There is no Eloquent, no migrations, no HTTP client.

## Features

### Money that actually adds up

Every amount is an immutable `Money` backed by [`brick/math`](https://github.com/brick/math)
arbitrary-precision decimals. `Money::of('0.1')->plus(Money::of('0.2'))`
is exactly `0.3`, and an invoice recomputed to the cent.

```php
$subtotal = Money::of('8.158')->multipliedBy('2.8783'); // 23.4811714
$tax      = $subtotal->percentage(Percentage::of(15));  //  3.52217571
$total    = $subtotal->plus($tax);                      // 27.00334711
```

### One energy model for combustion and electric vehicles

`EnergyQuantity` carries an amount together with its `EnergyUnit` (gallons,
litres or kilowatt-hours), and `EnergyEconomy` carries distance-per-unit
economy together with the same unit. A single API therefore serves a gasoline
car (km/gal, km/L, MPG) and an EV (km/kWh, kWh/100 km).

```php
EnergyEconomy::ofMilesPerGallon('25')->kilometresPerGallon();          // 40.23...
EnergyEconomy::ofKilometresPerGallon('10')->litresPer100Kilometres();  // 37.8541
EnergyEconomy::ofKilometresPerKilowattHour('6')->kilowattHoursPer100Kilometres(); // 16.6667

EnergyQuantity::ofLitres('3.785411784')->gallons(); // 1
EnergyQuantity::ofKilowattHours('42.5')->isElectric(); // true
```

### Trip profitability with a verdict

`TripProfitabilityCalculator` folds commission, energy, depreciation, fixed
costs and *deadhead* (unpaid) distance into a `TripCostBreakdown`, and returns
an `Accept` / `Marginal` / `Reject` verdict plus the **break-even fare** the
platform would have to pay for the trip to be worth driving.

```php
$result->breakdown->energyCost;           // cost of the energy consumed
$result->breakdown->profitPerKilometre(); // net profit per km
$result->breakdown->margin();             // net margin as a fraction
$result->breakEvenFare();                 // the fare that makes net profit zero
```

### Energy-log analytics

Feed an `EnergyLog` and get back the headline KPIs, monthly and time-of-day
series, and vendor rankings — including the **weighted price** (a ratio of
sums, never an average of prices):

```php
$analytics = new EnergyAnalytics($log);

$analytics->kpis();                    // count, energy, spend, weighted price, avg economy
$analytics->weightedPrice();           // total spend / total energy
$analytics->averageEconomy();          // average of usable readings only
$analytics->monthlySeries();           // spend/energy/distance per month
$analytics->vendorRanking();           // vendors by spend, with weighted price
$analytics->cheapestVendors();         // vendors by price, cheapest first
$analytics->bestEconomyVendors();      // vendors with >= 2 readings only
$analytics->timeOfDayBreakdown();      // dawn / morning / afternoon / night
$analytics->spendConcentration(2);     // share of spend in the top-N vendors
```

### Data-quality scoring

`DataQualityAnalyzer` flags the problems that quietly corrupt every driver's
records: missing dates, missing or non-monotonic odometer readings,
non-positive energy amounts, and statistical outliers detected with the
**median absolute deviation** (robust against extreme values).

```php
$analyzer = new DataQualityAnalyzer($log);

$analyzer->issues();            // every finding, with severity
$analyzer->hasErrors();         // are there blocking problems?
$analyzer->qualityScore();      // a single 0..1 health score
$analyzer->outlierEconomies();
```

## Installation

```bash
composer require julioserpone/gig-economics
```

Requires **PHP 8.3+** and `brick/math`. No other runtime dependency.

## Reading a delimited export

`EnergyLogImporter` reads a header-driven, **pipe-delimited** (`|`) file so the
same importer handles a gasoline log and an electric charging log. Fields are
wrapped in double quotes and a UTF-8 BOM is tolerated; a pipe avoids the
ambiguity of commas inside free-text fields such as vendor names.

```php
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Support\EnergyLogImporter;

$log = (new EnergyLogImporter)->fromFile('fuel_log.psv');
// or, for an electric charging log:
$log = (new EnergyLogImporter)->fromFile('electric_log.psv', 0.0, EnergyType::Electric);
// or ->fromString($contents) / ->fromRows($rows)
```

Recognized input columns are `date`, `time`, `vendor_tax_id`, `vendor_name`,
`energy`, `unit_price`, `odometer`, `energy_type` and `tax_rate`. Only **input**
columns are read: any calculated column may also be exported and is ignored and
recomputed by the domain, so a stale formula can never leak into your numbers.

## Architecture

```
src/
├── Domain/
│   ├── Money/        Currency, Money, Percentage          (value objects)
│   ├── Shared/       Distance, EnergyUnit, EnergyType, EnergyQuantity,
│   │                 EnergyEconomy, Platform, TimeOfDay
│   ├── Energy/       EnergyPrice, EnergyPurchase, EnergyLog
│   ├── Trip/         Fare, TripCostProfile, TripCostBreakdown,
│   │                 TripProfitabilityCalculator, TripVerdict
│   ├── Analytics/    EnergyAnalytics, EnergyKpis, VendorSummary, PeriodSummary
│   └── Quality/      DataQualityAnalyzer, DataQualityIssue, Severity
├── Support/          EnergyLogImporter
└── Exceptions/       CurrencyMismatchException, InvalidDomainValueException
```

The core is **pure domain code**: no I/O, no framework, no global state. The
`Support` layer is the only place that touches the outside world, which keeps
every calculation trivially testable and portable to any PHP project —
Laravel, Symfony, a CLI, or a long-running worker.

### Design principles

- **Immutability everywhere.** Every value object is `readonly`; operations
  return new instances. Reused profiles can never be mutated by a calculation.
- **No floats in money math.** Amounts travel as `BigDecimal` and are rounded
  only for presentation.
- **Invariants enforced at the boundary.** A negative distance, a zero-energy
  division, a mismatched unit or an unknown currency fails fast and loudly.
- **Drivetrain-agnostic.** The energy unit travels with the value, so the same
  calculation serves combustion and electric vehicles without branching on
  caller convention.
- **Platform-agnostic.** `Platform` ships defaults for Uber, DiDi, inDrive and
  Lyft, and `Platform::Custom` lets you model any other marketplace.

## Testing

```bash
composer test      # Pest 3/4 test suite
composer lint      # Laravel Pint
composer analyse   # PHPStan level max (with strict rules)
composer ci        # all of the above, in order
```

The suite pairs fast unit tests with integration tests that parse realistic
fixtures and assert the documented KPIs for both a combustion log and an
electric charging log.

## Contributing

Commits follow [Conventional Commits](https://www.conventionalcommits.org/) and
arrive through a `<type>/<summary>` branch — never directly on `main`. The full
workflow is documented in [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md).
`composer ci` must pass before a change is merged; the same script runs in CI
across PHP 8.3, 8.4 and 8.5 against both lowest and highest dependency versions.
AI assistants should follow the guidance in [`AGENTS.md`](AGENTS.md).

## License

MIT — see [LICENSE](LICENSE).
