# Gig Economics

[![Tests](https://github.com/julioserpone/gig-economics/actions/workflows/tests.yml/badge.svg)](https://github.com/julioserpone/gig-economics/actions/workflows/tests.yml)
[![Code Quality](https://github.com/julioserpone/gig-economics/actions/workflows/code-quality.yml/badge.svg)](https://github.com/julioserpone/gig-economics/actions/workflows/code-quality.yml)
[![Latest Version](https://img.shields.io/packagist/v/julioserpone/gig-economics.svg)](https://packagist.org/packages/julioserpone/gig-economics)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A decoupled, platform-agnostic PHP library that answers the only question a
ride-hailing driver really cares about: **did this trip make money?**

It computes fuel efficiency, operating costs and the real profitability of a
trip taken on **Uber, DiDi, inDrive, Lyft** — or any other marketplace — without
talking to any of them, without a database, and without ever losing a cent to
floating-point arithmetic.

```php
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\Platform;
use GigEconomics\Domain\Trip\Fare;
use GigEconomics\Domain\Trip\TripCostProfile;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;

$profile = TripCostProfile::create(
    fuelPricePerGallon: Money::of('4.8384'),
    fuelEfficiency: FuelEfficiency::ofKilometresPerGallon('53.32'),
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

## Why this library exists

Drivers see a **gross fare**. What actually reaches their pocket is that fare
minus the platform commission, minus the fuel burned, minus the kilometres
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

### Fuel efficiency in any unit

`Distance`, `FuelVolume` and `FuelEfficiency` convert between the units drivers
and platforms actually use: km/gal, km/L, MPG, litres per 100 km, US gallons,
litres, miles.

```php
FuelEfficiency::ofMilesPerGallon('25')->kilometresPerGallon(); // 40.23...
FuelEfficiency::ofKilometresPerGallon('10')->litresPer100Kilometres(); // 37.8541
```

### Trip profitability with a verdict

`TripProfitabilityCalculator` folds commission, fuel, depreciation, fixed costs
and *deadhead* (unpaid) distance into a `TripCostBreakdown`, and returns an
`Accept` / `Marginal` / `Reject` verdict plus the **break-even fare** the
platform would have to pay for the trip to be worth driving.

```php
$result->breakdown->fuelCost;            // cost of the fuel burned
$result->breakdown->profitPerKilometre(); // net profit per km
$result->breakdown->margin();             // net margin as a fraction
$result->breakEvenFare();                 // the fare that makes net profit zero
```

### Fuel-log analytics

Feed a `FuelLog` and get back the headline KPIs, monthly and time-of-day
series, and vendor rankings — including the **volume-weighted price** (a ratio
of sums, never an average of prices):

```php
$analytics = new FuelAnalytics($log);

$analytics->kpis();                    // count, volume, spend, weighted price, avg efficiency
$analytics->weightedPrice();           // SUM(Monto) / SUM(Galones)
$analytics->averageEfficiency();       // average of usable Km/Galon only
$analytics->monthlySeries();           // spend/volume/distance per month
$analytics->vendorRanking();           // vendors by spend, with weighted price
$analytics->cheapestVendors();         // vendors by price, cheapest first
$analytics->bestEfficiencyVendors();   // vendors with >= 2 refuels only
$analytics->timeOfDayBreakdown();      // dawn / morning / afternoon / night
$analytics->spendConcentration(2);     // share of spend in the top-N vendors
```

### Data-quality scoring

`DataQualityAnalyzer` flags the problems that quietly corrupt every driver's
spreadsheet: missing dates, missing or non-monotonic odometer readings,
non-positive volumes, and statistical outliers detected with the **median
absolute deviation** (robust against extreme values).

```php
$analyzer = new DataQualityAnalyzer($log);

$analyzer->issues();           // every finding, with severity
$analyzer->hasErrors();        // are there blocking problems?
$analyzer->qualityScore();     // a single 0..1 health score
$analyzer->outlierEfficiencies();
```

## Installation

```bash
composer require julioserpone/gig-economics
```

Requires **PHP 8.3+** and `brick/math`. No other runtime dependency.

## Reading a spreadsheet export

`FuelLogCsvImporter` understands the shape spreadsheet tools actually export,
including the doubled-quote convention (`""Fecha""`) and a UTF-8 BOM:

```php
use GigEconomics\Support\FuelLogCsvImporter;

$log = (new FuelLogCsvImporter)->fromFile('tblGasolina.csv');
// or ->fromString($csv) / ->fromRows($rows)
```

Only **input** columns are read. Calculated columns such as `Monto` or
`Km/Galón` are deliberately ignored and recomputed by the domain, so a stale
spreadsheet formula can never leak into your numbers.

## Architecture

```
src/
├── Domain/
│   ├── Money/        Currency, Money, Percentage          (value objects)
│   ├── Shared/       Distance, FuelVolume, FuelEfficiency, Platform, TimeOfDay
│   ├── Fuel/         FuelPrice, FuelPurchase, FuelLog
│   ├── Trip/         Fare, TripCostProfile, TripCostBreakdown,
│   │                 TripProfitabilityCalculator, TripVerdict
│   ├── Analytics/    FuelAnalytics, FuelKpis, VendorSummary, PeriodSummary
│   └── Quality/      DataQualityAnalyzer, DataQualityIssue, Severity
├── Support/          FuelLogCsvImporter
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
- **Invariants enforced at the boundary.** A negative distance, a zero-volume
  division, or an unknown currency fails fast and loudly.
- **Platform-agnostic.** `Platform` ships defaults for Uber, DiDi, inDrive and
  Lyft, and `Platform::Custom` lets you model any other marketplace.

## Testing

```bash
composer test      # Pest 3/4 test suite
composer lint      # Laravel Pint
composer analyse   # PHPStan level max (with strict rules)
composer ci        # all of the above, in order
```

The suite pairs fast unit tests with integration tests that parse a real
84-row `tblGasolina` export and assert the documented KPIs (total volume
`395.24`, weighted price `4.838450`, 13 vendors, top-2 concentration
`0.826383`).

## Contributing

See the pull-request template. `composer ci` must pass before a change is
merged; the same script runs in CI across PHP 8.3, 8.4 and 8.5 against both
lowest and highest dependency versions.

## License

MIT — see [LICENSE](LICENSE).
