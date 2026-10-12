# Release Notes

All notable changes to `gig-economics` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Electric-vehicle support: `EnergyUnit` (gallons, litres, kWh), `EnergyType`
  (includes `Electric`) and `EnergyQuantity`, so a recharge is modelled the
  same way as a refuelling.
- `EnergyEconomy` expresses efficiency in km/gal, km/L or km/kWh (and
  kWh/100 km), with `EnergyEconomy::fromDistanceAndEnergy()` deriving the value
  for either drivetrain.
- `TripCostProfile::forElectric()` prices a trip per kilowatt-hour alongside
  the existing `forCombustion()` factory.
- `Configuration`, an immutable, framework-agnostic object holding the library
  defaults (tax rate, default energy type, profit target). It builds from
  `defaults()`, an explicit array (`fromArray()`) or the environment
  (`fromEnvironment()`), and never calls `env()` or a framework helper.
- `TripProfitabilityFactory` bridges a `Configuration` to the pure domain by
  building a `TripProfitabilityCalculator` with the configured profit target.

### Changed

- Renamed the fuel-specific domain to energy: `FuelPurchase` ->`EnergyPurchase`,
  `FuelPrice` ->`EnergyPrice`, `FuelLog` ->`EnergyLog`, `FuelAnalytics` ->
  `EnergyAnalytics`, `FuelKpis` ->`EnergyKpis`, `FuelLogCsvImporter` ->
  `EnergyLogImporter`.
- `TripCostProfile` now exposes `energyPricePerUnit`/`energyEconomy` and
  `energyCostFor()`; `TripCostBreakdown` exposes `energyCost`.
- `EnergyLogImporter` reads a header-driven, English-column layout
  (`date`, `energy`, `unit_price`, `odometer`, `energy_type`, ...) instead of
  the previous fixed spreadsheet layout.
- Imports are now **pipe-delimited** (`|`, `.psv`) with quoted fields, because
  free-text values such as vendor names routinely contain commas.
- `EnergyLogImporter` takes an optional `Configuration`; its `fromRows()`,
  `fromString()` and `fromFile()` tax rate and energy type now default to the
  configured values (an explicit call argument still overrides them).

### Removed

- All references to the source spreadsheet and its column names, from code,
  comments, tests and documentation.

## [1.0.0] - 2026-10-12

### Added

- Immutable money primitives: `Money`, `Currency`, `Percentage` backed by
  arbitrary-precision decimals (no floating-point drift).
- Physical units: `Distance` and the energy units with conversions between
  km/gal, km/L, MPG, litres per 100 km, gallons and litres.
- Energy domain: `EnergyPrice`, `EnergyPurchase` and `EnergyLog`, recomputing
  subtotal, tax and total from the raw inputs.
- Trip profitability: `TripProfitabilityCalculator` returning a full
  `TripCostBreakdown`, an `Accept`/`Marginal`/`Reject` verdict and the
  break-even fare. Supports commission, depreciation, fixed costs and
  deadhead (unpaid) distance.
- Platform presets for Uber, DiDi, inDrive, Lyft and any custom marketplace.
- Energy analytics: headline KPIs, weighted price, monthly and time-of-day
  series, vendor rankings and spend concentration.
- Data-quality scoring with median-absolute-deviation outlier detection.
- `EnergyLogImporter` for CSV exports (doubled quotes + UTF-8 BOM).
- Pest test suite (unit + integration), PHPStan level max, Laravel Pint and
  GitHub Actions CI across PHP 8.3, 8.4 and 8.5.
