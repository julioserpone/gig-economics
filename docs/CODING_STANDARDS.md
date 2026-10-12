# Coding standards

These rules are enforced by `composer ci`. Read them together with
[`AGENTS.md`](../AGENTS.md).

## Language and syntax

- Every file starts with `<?php` on its own line, then a blank line, then
  `declare(strict_types=1);`. Pint enforces this (`declare_strict_types`).
- One class, interface or enum per file, named after the file.
- Use PHP 8.3+ syntax: `readonly` promoted properties, constructor property
  promotion, enums, `match`, named arguments and first-class callable syntax.
- Namespaces follow PSR-4 (`GigEconomics\` → `src/`, `GigEconomics\Tests\` →
  `tests/`).

## Naming

- Classes are `PascalCase`; methods and variables are `camelCase`; constants are
  `SCREAMING_SNAKE_CASE`.
- Name things for what they mean in the domain, not for where the data came
  from. Dominant nouns here are **energy**, **economy**, **trip**, **fare**,
  **vendor** and **platform**.
- Units of measurement are part of method names: `kilowattHours()`,
  `kilometresPerLitre()`, `litresPer100Kilometres()`.
- Predicate methods read as a question and return `bool`: `isUsable()`,
  `isElectric()`, `hasOdometer()`.
- Factories read as what they build: `ofGallons()`, `perKilowattHour()`,
  `fromDistanceAndEnergy()`, `forElectric()`.

## Visibility and immutability

- Prefer `private readonly` properties and expose behaviour, not state. Public
  readonly properties are acceptable on plain data carriers (e.g.
  `TripCostBreakdown`), never on something with invariants.
- A value object's constructor is `private` when a named factory must validate
  the value first (`Money`, `EnergyQuantity`, `EnergyEconomy`, `Percentage`).
- Never expose a setter. Every "mutation" returns a new instance
  (`plus()`, `minus()`, `roundedTo()`, `add()`).

## Money, energy and decimals

- Never touch `float` for value arithmetic. Widened signatures accept
  `string|int|float|BigDecimal` **only** at the outer edge (public factories),
  and a private `parse()` converts to `BigDecimal` immediately.
- Round only for presentation, at currency scale, with an explicit mode
  (`RoundingMode::HALF_UP` by default). Intermediate math keeps full precision.
- Both operands of an operation must share a currency, and an `EnergyQuantity`
  operation must share a unit. Mismatches throw
  `CurrencyMismatchException` / `InvalidDomainValueException`.

## Drivetrain neutrality

- Do not branch on a boolean to decide the physics. The unit carried by an
  `EnergyQuantity` or `EnergyEconomy` decides what is legal: asking an electric
  economy for `kilometresPerGallon()` is a domain error, not a zero.
- Combustion and electric vehicles share every calculation path. If you feel the
  need for parallel `Electric*` classes, model the difference as data instead.

## Errors

- Throw `InvalidDomainValueException` via its named constructors
  (`negative()`, `divisionByZero()`, `outOfRange()`); do not construct raw
  `\InvalidArgumentException` in the domain.
- Message format: state the concept, then the offending value, e.g.
  `"The distance cannot be negative. Received: -1."`.
- Never swallow an exception to keep a calculation running; surface it.

## Configuration

- Editable defaults belong in `Support\Configuration`, never inline as magic
  numbers or string literals sprinkled across classes.
- The library never reads configuration itself from the environment: it exposes
  `fromArray()` and `fromEnvironment()` and lets the caller choose. Never call
  `env()`, `config()` or a framework helper anywhere in `src/`.
- The pure domain must not import `Support\Configuration`. Bridge it in the
  `Support` layer (e.g. `TripProfitabilityFactory`) and pass plain values into
  the domain.
- Configuration is immutable: add a `with*()` method returning a new instance
  rather than mutating state.

## Documentation

- Every public class and non-obvious method has a concise docblock that explains
  **why**, not a restatement of the signature.
- No Spanish identifiers, no spreadsheet/Excel/"workbook" vocabulary, no
  references to any particular file format or product in domain code.
- Keep the README and CHANGELOG in step with public behaviour changes.

## Formatting

- Tabs for indentation, LF endings, final newline (`EditorConfig`).
- Run `composer lint` before committing; CI runs `composer lint:check`.
- Import ordering is alphabetical and unused imports must be removed.
