# CLAUDE.md

See [`AGENTS.md`](AGENTS.md) for the full guide to working in this repository.
This file only points to it so every assistant finds the same instructions.

Quick reference:

- Run `composer ci` (lint + PHPStan level max + Pest) before finishing a change.
- No floats for money or energy; go through `Money` / `EnergyQuantity` /
  `EnergyEconomy` / `BigDecimal`.
- The domain is pure (`src/Domain/**`), I/O lives only in `src/Support/**`.
- Combustion and electric vehicles share one code path; the energy unit decides
  the physics. Never add spreadsheet/Excel vocabulary or Spanish column names.
- Coding rules: [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md).
- Test rules: [`docs/TESTING.md`](docs/TESTING.md).
- Commit/PR rules: [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) — use
  Conventional Commits on a `<type>/<summary>` branch, never on `main`.
