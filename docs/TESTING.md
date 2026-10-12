# Testing guide

Gig Economics uses [Pest](https://pestphp.com/) 3/4 on top of PHPUnit. Tests are
part of the definition of "done": a behavioural change without a test is an
incomplete change. `composer ci` runs the suite and must be green before a
change is merged.

## Layout

```
tests/
├── Pest.php                  # binds the base TestCase to Unit and Integration
├── TestCase.php              # base class; exposes fixture(string $name)
├── Unit/                     # fast, in-memory, no filesystem
│   ├── MoneyTest.php
│   ├── UnitsTest.php
│   ├── EnergyPurchaseTest.php
│   ├── EnergyLogImporterTest.php
│   ├── DataQualityAnalyzerTest.php
│   └── TripProfitabilityTest.php
├── Integration/              # parses a fixture file end to end
│   └── EnergyLogFixtureTest.php
└── Fixtures/
    ├── fuel_log.psv          # 84-row combustion log (pipe-delimited)
    └── electric_log.psv      # 4-row electric charging log (pipe-delimited)
```

## Conventions

- **Pest functional style.** Use `it('...', function (): void { ... })` with a
  behaviour-focused description; never write a test name that restates a method
  name.
- **One behaviour per test.** If the description needs an "and", split it.
- **Arrange / Act / Assert.** Build the object, call the one method under test,
  then assert. Avoid multiple unrelated actions.
- **`expect(...)` chains.** Prefer a single `expect()` chain with `->and(...)`
  over several `expect()` calls.
- **Types in closures.** Always annotate test closures and helpers with their
  parameter and return types; PHPStan strict rules apply to intent even where
  tests are excluded from analysis.
- **Determinism.** No `sleep`, no `now()`, no randomness, no dependence on the
  machine clock. Pass dates explicitly (`new DateTimeImmutable('2026-04-01 ...')`).
- **No network, no real files** in unit tests. File I/O belongs in
  `Integration/` and must go through `$this->fixture('name.psv')`.

## What must be covered

Every public method you add or change needs at least:

1. **A happy path** with a concrete, hand-computed expected value.
2. **A boundary case** — zero, empty collection, first/last element.
3. **A failure case** — the invariant that throws, asserted with
   `->throws(InvalidDomainValueException::class)`.

Specifically, treat these as must-test:

- Value-object arithmetic and every unit conversion (round-trip where sensible).
- Rounding behaviour at the currency scale.
- Unit/currency mismatch errors (`CurrencyMismatchException`,
  `InvalidDomainValueException`).
- **Both drivetrains**: a combustion case and an equivalent electric case go
  through the same code path and must each be asserted.

Example:

```php
it('computes km per kilowatt-hour from distance and energy', function (): void {
    $economy = EnergyEconomy::fromDistanceAndEnergy(
        Distance::ofKilometres(300),
        EnergyQuantity::ofKilowattHours('50'),
    );

    expect($economy->toFloat())->toBe(6.0)
        ->and($economy->unit)->toBe(EnergyUnit::KilowattHours)
        ->and(round($economy->kilowattHoursPer100Kilometres()->toFloat(), 4))->toBe(16.6667);
});
```

## Fixtures

- Fixtures live in `tests/Fixtures/` and use **generic English column names**:
  `date`, `time`, `vendor_tax_id`, `vendor_name`, `energy`, `unit_price`,
  `odometer`, `energy_type`, `tax_rate`.
- Fixtures are **pipe-delimited** (`|`) with every field wrapped in double
  quotes and the `.psv` extension. A pipe is unambiguous because free-text
  fields such as vendor names routinely contain commas.
- Never commit a fixture whose calculated columns are treated as the source of
  truth; the importer must recompute them. A fixture may include a deliberately
  wrong calculated column to prove it is ignored.
- Keep fixtures small but realistic. Add a dedicated electric fixture rather
  than mixing units in one file.
- State the expected aggregates in the test with the exact precision the library
  promises (e.g. `round($value, 6)`).

## Running

```bash
composer test                     # whole suite
vendor/bin/pest tests/Unit        # one suite
vendor/bin/pest --filter=electric # one name
composer test:coverage            # with coverage
```

## Definition of done

- [ ] New/changed behaviour is covered per the rules above.
- [ ] `composer ci` passes locally (lint, PHPStan, tests).
- [ ] Fixtures contain no Spanish or spreadsheet-specific vocabulary.
- [ ] Both combustion and electric paths are exercised where relevant.
