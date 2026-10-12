# Release Notes

All notable changes to `gig-economics` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-12

### Added

- Immutable money primitives: `Money`, `Currency`, `Percentage` backed by
  arbitrary-precision decimals (no floating-point drift).
- Physical units: `Distance`, `FuelVolume`, `FuelEfficiency` with conversions
  between km/gal, km/L, MPG, litres per 100 km, gallons and litres.
- Fuel domain: `FuelPrice`, `FuelPurchase` and `FuelLog`, reproducing the
  `Valor total`, `Iva 15%`, `Monto`, `Km Recorridos` and `Km/Galón` columns.
- Trip profitability: `TripProfitabilityCalculator` returning a full
  `TripCostBreakdown`, an `Accept`/`Marginal`/`Reject` verdict and the
  break-even fare. Supports commission, depreciation, fixed costs and
  deadhead (unpaid) distance.
- Platform presets for Uber, DiDi, inDrive, Lyft and any custom marketplace.
- Fuel analytics: headline KPIs, volume-weighted price, monthly and
  time-of-day series, vendor rankings and spend concentration.
- Data-quality scoring with median-absolute-deviation outlier detection.
- `FuelLogCsvImporter` for spreadsheet exports (doubled quotes + UTF-8 BOM).
- Pest test suite (unit + integration), PHPStan level max, Laravel Pint and
  GitHub Actions CI across PHP 8.3, 8.4 and 8.5.
