# AGENTS.md — working in this repository

This file is the entry point for any AI assistant (or new contributor) making
changes to **Gig Economics**. Read it before editing code. It describes the
architecture, the non-negotiable conventions, and the exact commands that must
pass before a change is considered done.

## What this project is

A **stateless, dependency-light PHP library** that measures the real
profitability of a trip taken on a ride-hailing platform (Uber, DiDi, inDrive,
Lyft, ...) for **combustion and electric vehicles**. It takes data in and
returns numbers; it never performs I/O from the domain layer.

- Language: PHP **8.3+**
- Runtime dependency: [`brick/math`](https://github.com/brick/math) only
- Tests: [Pest](https://pestphp.com/) 3/4 on top of PHPUnit
- Static analysis: PHPStan at **level max** with strict rules
- Formatting: Laravel Pint

## Golden rules

1. **Never use floats for money or energy.** All arithmetic goes through
   `Money`, `Percentage`, `EnergyQuantity`, `EnergyEconomy` and
   `BigDecimal`. A raw `float` is only ever accepted at the edge of a public
   factory and is immediately parsed into a `BigDecimal`.
2. **Value objects are immutable.** Every domain object is a `final class` with
   `readonly` properties (or an `enum`). Operations return **new** instances;
   nothing is mutated in place.
3. **Invariants fail fast and loudly.** Invalid input throws
   `InvalidDomainValueException` (or `CurrencyMismatchException`), never
   silently coerces. See `src/Exceptions/`.
4. **The domain is pure.** `src/Domain/**` must not touch the filesystem,
   environment, network, clock or any framework. Only `src/Support/**` may do
   I/O.
5. **Drivetrain-agnostic by data, not by branch.** Combustion and electric
   vehicles are modelled through the same types (`EnergyQuantity`,
   `EnergyEconomy`, `EnergyUnit`). Do **not** fork behaviour on an
   `if ($isElectric)` flag in the domain; let the unit carried by the value
   decide.
6. **No hidden dependencies.** Do not add a runtime `composer require` without
   a strong justification; this library is intentionally small.
7. **Configuration is explicit and framework-agnostic.** Defaults live in the
   immutable `Support\Configuration` object; the pure domain never reads it.
   Never call `env()`, `config()` or any framework helper inside the library —
   the caller decides where values come from.

## Never do this

- **Do not reintroduce spreadsheet/Excel concepts.** There is no
  "workbook", no fixed spreadsheet layout, and no Spanish column names anywhere
  in code, comments, tests, fixtures or docs. If you find a leftover reference,
  remove it. Column names are the generic English ones documented in
  `src/Support/EnergyLogImporter.php`.
- Do not use `float` for value arithmetic, even "just for a quick check".
- Do not mutate a `readonly` object by rebuilding it with a public setter.
- Do not put business logic in `src/Support/**`; it belongs in `src/Domain/**`.
- Do not add `else` branches that merely restate a `default`; prefer `match`
  with exhaustive arms over enums.
- Do not lower PHPStan's level or exclude new paths to make a change pass.

## Architecture map

```
src/
├── Domain/
│   ├── Money/        Currency, Money, Percentage        (immutable value objects)
│   ├── Shared/       Distance, EnergyUnit, EnergyType, EnergyQuantity,
│   │                 EnergyEconomy, Platform, TimeOfDay, RoundingMode
│   ├── Energy/       EnergyPrice, EnergyPurchase, EnergyLog
│   ├── Trip/         Fare, TripCostProfile, TripCostBreakdown, TripCosts,
│   │                 TripProfitabilityCalculator, TripProfitability, TripVerdict
│   ├── Analytics/    EnergyAnalytics, EnergyKpis, VendorSummary,
│   │                 PeriodSummary, TimeOfDaySummary
│   └── Quality/      DataQualityAnalyzer, DataQualityIssue,
│                     DataQualityIssueType, Severity
├── Support/          EnergyLogImporter, Configuration,
│                     TripProfitabilityFactory              (the only I/O)
└── Exceptions/       CurrencyMismatchException, InvalidDomainValueException
```

Key ideas:

- **`EnergyUnit`** (`gallons`, `litres`, `kwh`) says how energy is measured.
- **`EnergyType`** (`RegularGasoline`, `Diesel`, ..., `Electric`) says what the
  vehicle runs on and resolves to a unit and an `isElectric()` flag.
- **`EnergyQuantity`** is an amount of energy in one unit; it converts between
  liquid units but refuses to convert to/from `kWh` (a different dimension).
- **`EnergyEconomy`** is distance per unit of energy; it exposes km/gal, km/L,
  km/kWh, MPG and per-100 km helpers, and errors when the wrong dimension is
  requested.
- **`TripCostProfile`** holds the reusable assumptions; `forCombustion()` and
  `forElectric()` are the friendly factories over the generic `create()`.
- **`Configuration`** (`Support`) holds the editable defaults (tax rate, default
  energy type, profit target) and builds from an array or the environment. The
  domain stays pure: values are always passed in explicitly, never read from it.

## Workflow for a change

1. **Understand before editing.** Read the touched classes end to end; use the
   architecture map above to find neighbours.
2. **Smallest change that fully solves the problem.** Prefer targeted edits over
   rewrites. Do not rename or restructure unrelated code.
3. **Update or add tests** for every behavioural change (see `docs/TESTING.md`).
4. **Run the full pipeline** and make it green before you stop:

   ```bash
   composer ci   # lint:check + analyse + test
   ```

5. **Update docs** when you change public behaviour: `README.md` and
   `CHANGELOG.md` (under `## [Unreleased]`).
6. **Commit and open a PR following the conventions** in
   [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md): a `<type>/<summary>` branch,
   [Conventional Commits](https://www.conventionalcommits.org/) messages, and a
   focused pull request. Never commit directly to `main`.

## Commands

| Command                  | What it does                                            |
| ------------------------ | ------------------------------------------------------- |
| `composer test`          | Run the Pest suite                                      |
| `composer test:coverage` | Run the suite with coverage                             |
| `composer lint`          | Auto-fix style with Laravel Pint                        |
| `composer lint:check`    | Verify style without writing (used by CI)               |
| `composer analyse`       | PHPStan level max with strict rules                     |
| `composer ci`            | `lint:check` + `analyse` + `test`, in that order        |

`composer ci` **must** pass locally before a change is merged. CI runs it across
PHP 8.3, 8.4 and 8.5 against both the lowest and the highest allowed dependency
versions, so avoid relying on behaviour that only exists in the newest package
release.

## Detailed guides

- [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md) — naming, typing,
  value-object and error-handling conventions.
- [`docs/TESTING.md`](docs/TESTING.md) — how tests are structured, what must be
  covered, and how to write fixtures.
- [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) — branch, Conventional Commits
  and pull-request conventions. **Every commit and PR message must follow it.**
