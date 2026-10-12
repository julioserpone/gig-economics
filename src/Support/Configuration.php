<?php

declare(strict_types=1);

namespace GigEconomics\Support;

use GigEconomics\Domain\Shared\EnergyType;

/**
 * Immutable runtime configuration for the library's I/O-facing components.
 *
 * The domain is pure and never reads configuration; values are always passed to
 * it explicitly. This object exists so the `Support` layer and the applications
 * built on top of the library can share one set of defaults without hard-coding
 * them.
 *
 * It is deliberately framework-agnostic:
 *
 *  - `defaults()` returns the built-in values with no environment access;
 *  - `fromArray()` accepts an explicit array, so a Laravel app can pass
 *    `config('gig-economics')` while a plain CLI can pass its own array;
 *  - `fromEnvironment()` reads `GIG_ECONOMICS_*` variables for the small set of
 *    values that are safe to override from the process environment.
 *
 * Nothing here calls `env()` or a framework helper; the caller decides where the
 * values come from. Configuration is immutable — `with*()` methods return a new
 * instance and never mutate the original.
 */
final class Configuration
{
    /**
     * Environment variable names read by {@see fromEnvironment()}.
     */
    public const ENV_TAX_RATE = 'GIG_ECONOMICS_TAX_RATE';

    public const ENV_DEFAULT_ENERGY_TYPE = 'GIG_ECONOMICS_DEFAULT_ENERGY_TYPE';

    public const ENV_TARGET_PROFIT_PER_KILOMETRE = 'GIG_ECONOMICS_TARGET_PROFIT_PER_KILOMETRE';

    public function __construct(
        public readonly float $defaultTaxRatePercent = 15.0,
        public readonly EnergyType $defaultEnergyType = EnergyType::RegularGasoline,
        public readonly string $defaultTargetProfitPerKilometre = '0.15',
    ) {}

    /**
     * The built-in defaults, with no environment or framework access.
     */
    public static function defaults(): self
    {
        return new self;
    }

    /**
     * Build from an explicit associative array, e.g. a framework config file.
     *
     * Unknown keys are ignored so a published config file can grow without
     * breaking older callers.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = self::defaults();

        $taxRate = self::floatOrNull($values['tax_rate_percent'] ?? null);

        $energyType = self::energyTypeOrNull($values['default_energy_type'] ?? null)
            ?? $defaults->defaultEnergyType;

        $target = self::stringOrNull($values['target_profit_per_kilometre'] ?? null);

        return new self(
            defaultTaxRatePercent: $taxRate ?? $defaults->defaultTaxRatePercent,
            defaultEnergyType: $energyType,
            defaultTargetProfitPerKilometre: $target ?? $defaults->defaultTargetProfitPerKilometre,
        );
    }

    /**
     * Build from the process environment, falling back to the defaults for any
     * variable that is unset or empty.
     */
    public static function fromEnvironment(): self
    {
        /** @var array<string, mixed> $values */
        $values = [
            'tax_rate_percent' => self::environment(self::ENV_TAX_RATE),
            'default_energy_type' => self::environment(self::ENV_DEFAULT_ENERGY_TYPE),
            'target_profit_per_kilometre' => self::environment(self::ENV_TARGET_PROFIT_PER_KILOMETRE),
        ];

        return self::fromArray($values);
    }

    /**
     * Return a copy with a different default tax rate.
     */
    public function withTaxRatePercent(float $percent): self
    {
        return new self($percent, $this->defaultEnergyType, $this->defaultTargetProfitPerKilometre);
    }

    /**
     * Return a copy with a different default energy type.
     */
    public function withDefaultEnergyType(EnergyType $energyType): self
    {
        return new self($this->defaultTaxRatePercent, $energyType, $this->defaultTargetProfitPerKilometre);
    }

    /**
     * Return a copy with a different default profit target per kilometre.
     */
    public function withTargetProfitPerKilometre(string $amount): self
    {
        return new self($this->defaultTaxRatePercent, $this->defaultEnergyType, $amount);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tax_rate_percent' => $this->defaultTaxRatePercent,
            'default_energy_type' => $this->defaultEnergyType->value,
            'target_profit_per_kilometre' => $this->defaultTargetProfitPerKilometre,
        ];
    }

    private static function environment(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        if ($value === null) {
            $fromGetenv = getenv($name);
            $value = $fromGetenv === false ? null : $fromGetenv;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function floatOrNull(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return is_numeric($normalized) ? $normalized : null;
    }

    /**
     * Resolve an {@see EnergyType} from a case value, tolerating surrounding
     * whitespace and a human-friendly `"Regular Gasoline"` spelling.
     */
    private static function energyTypeOrNull(mixed $value): ?EnergyType
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return EnergyType::tryFrom(strtolower(str_replace(' ', '_', trim($value))));
    }
}
