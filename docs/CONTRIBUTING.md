# Contributing & Git conventions

This guide defines how changes reach the repository: the branch model, the
commit message format, and how pull requests are written. Follow it for every
change, whether it comes from a human or an AI assistant.

## Branch model

`main` is always releasable. Never commit directly to `main`; every change
arrives through a pull request from a short-lived branch.

Branch naming uses the same type vocabulary as commits:

```
<type>/<short-kebab-summary>
```

Examples:

```
feat/electric-vehicle-support
fix/weighted-price-rounding
docs/git-conventions
refactor/rename-energy-domain
test/pipe-delimited-fixtures
chore/bump-phpstan
```

- Keep the summary short, lowercase and kebab-cased.
- One logical change per branch. Rebase on `main` before opening the PR.

## Commit messages — Conventional Commits

Commits follow the [Conventional Commits](https://www.conventionalcommits.org/)
specification. The subject line is:

```
<type>(<optional scope>): <imperative summary>
```

- **type** — one of the values in the table below.
- **scope** *(optional)* — the affected area, e.g. `trip`, `analytics`,
  `energy`, `money`, `docs`, `ci`. Use the module or domain concept, not a file.
- **summary** — imperative mood ("add", not "added"/"adds"), lowercase, no
  trailing period, 72 characters or fewer.

### Types

| Type       | Use for                                                       |
| ---------- | ------------------------------------------------------------- |
| `feat`     | A new user-facing capability                                  |
| `fix`      | A bug fix                                                     |
| `refactor` | A change that neither fixes a bug nor adds a feature          |
| `perf`     | A change that improves performance                            |
| `test`     | Adding or correcting tests only                               |
| `docs`     | Documentation only                                            |
| `build`    | Build tooling or dependencies (Composer, PHP tooling)         |
| `ci`       | Continuous-integration configuration                          |
| `chore`    | Maintenance that fits no other type                           |
| `style`    | Formatting/whitespace without a behavioural change            |

### Body and footers

- Leave one blank line after the subject, then explain **what** and **why**
  (not how). Wrap at 72 characters.
- Use `BREAKING CHANGE:` in the footer to flag an incompatible change, and
  describe the migration.
- Reference issues in the footer with `Refs: #123` / `Closes: #123`.

### Examples

```
feat(energy): support electric vehicles alongside combustion

Model every replenishment as an EnergyQuantity carrying its EnergyUnit
(gallons, litres or kWh) so a recharge and a refuelling share one code
path. Add EnergyEconomy for km/kWh and a TripCostProfile::forElectric()
factory.

Refs: #42
```

```
refactor(energy): rename the fuel domain to energy

FuelPurchase, FuelPrice, FuelLog, FuelAnalytics, FuelKpis and the CSV
importer become the Energy* equivalents. No behaviour changes.
```

```
test(importer): switch fixtures to a pipe-delimited format

Vendor names contain commas, which made the previous separator
ambiguous. Fixtures are now .psv with quoted pipe-delimited fields.
```

```
docs: document commit and pull-request conventions

Refs: #51
```

## Pull requests

Write the PR against the repository's template and keep it focused.

- **Title** — same format as a commit subject, e.g.
  `feat(energy): support electric vehicles alongside combustion`.
- **Description** — start with a one-paragraph summary of the change and the
  problem it solves, then:
  - **What changed** — a bulleted list of the notable changes.
  - **Why** — the motivation or linked issue.
  - **How to test** — the commands a reviewer runs (`composer ci`).
  - **Checklist** — tests added/updated, docs updated, `composer ci` green.
- **Size** — one logical change. Split unrelated work into separate PRs.
- **Breaking changes** — call them out explicitly at the top.

A pull request is ready to merge only when `composer ci` passes, the docs and
`CHANGELOG.md` are updated for public behaviour changes, and the description
explains how the change was verified.

## Definition of done

- [ ] The branch is up to date with `main` and named `<type>/<summary>`.
- [ ] Commits follow Conventional Commits, each one self-contained.
- [ ] `composer ci` passes locally.
- [ ] Tests cover the new or changed behaviour (see `docs/TESTING.md`).
- [ ] `README.md` and `CHANGELOG.md` are updated for public behaviour changes.
- [ ] The PR description explains what, why and how to test.
