<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Exceptions\InvalidDomainValueException;
use JsonSerializable;
use Stringable;

/**
 * Immutable amount of energy a vehicle takes on board.
 *
 * The value is always carried in a single canonical base unit together with
 * the {@see EnergyUnit} it was expressed in, so combustion vehicles
 * (gallons/litres) and electric vehicles (kilowatt-hours) share one arithmetic
 * type. Conversions between combustion units stay available; kWh is a
 * different dimension and is therefore never converted to a volume.
 */
final class EnergyQuantity implements JsonSerializable, Stringable
{
    private const LITRES_PER_GALLON = '3.785411784';

    /**
     * Canonical base values, expressed in the unit recorded in `$unit`.
     */
    private function __construct(
        private readonly BigDecimal $amount,
        public readonly EnergyUnit $unit,
    ) {}

    /**
     * Build from a volume in US gallons (combustion vehicles).
     */
    public static function ofGallons(string|int|float $gallons): self
    {
        return self::positive($gallons, EnergyUnit::Gallons);
    }

    /**
     * Build from a volume in litres (combustion vehicles).
     */
    public static function ofLitres(string|int|float $litres): self
    {
        return new self(self::parse($litres), EnergyUnit::Litres);
    }

    /**
     * Build from electrical energy in kilowatt-hours (electric vehicles).
     */
    public static function ofKilowattHours(string|int|float $kilowattHours): self
    {
        return self::positive($kilowattHours, EnergyUnit::KilowattHours);
    }

    /**
     * Build from an arbitrary amount expressed in an explicit unit.
     */
    public static function of(string|int|float|BigDecimal $amount, EnergyUnit $unit): self
    {
        return new self(self::parse($amount), $unit);
    }

    /**
     * A zero quantity in the given unit.
     */
    public static function zero(EnergyUnit $unit = EnergyUnit::Gallons): self
    {
        return new self(BigDecimal::zero(), $unit);
    }

    /**
     * Whether the quantity is electrical energy rather than a liquid fuel.
     */
    public function isElectric(): bool
    {
        return $this->unit->isElectric();
    }

    /**
     * Add two quantities. Mixing kWh with a liquid volume is a domain error.
     */
    public function plus(self $other): self
    {
        $this->assertSameUnit($other);

        return new self($this->amount->plus($other->amount), $this->unit);
    }

    /**
     * Subtract two quantities of the same unit.
     */
    public function minus(self $other): self
    {
        $this->assertSameUnit($other);

        return new self($this->amount->minus($other->amount), $this->unit);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameUnit($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    /**
     * Raw amount in the recorded unit.
     */
    public function toDecimal(): BigDecimal
    {
        return $this->amount;
    }

    public function toFloat(): float
    {
        return $this->amount->toFloat();
    }

    /**
     * Value expressed in US gallons. Electric energy has no volume, so the
     * call is a domain error there.
     */
    public function gallons(int $scale = 9): BigDecimal
    {
        return match ($this->unit) {
            EnergyUnit::Gallons => $this->amount,
            EnergyUnit::Litres => $this->amount->dividedBy(self::LITRES_PER_GALLON, $scale, BrickRoundingMode::HALF_UP),
            EnergyUnit::KilowattHours => throw InvalidDomainValueException::outOfRange('energy quantity in gallons', 'kilowatt-hours'),
        };
    }

    /**
     * Value expressed in litres. Electric energy has no volume, so the call is
     * a domain error there.
     */
    public function litres(int $scale = 6): BigDecimal
    {
        return match ($this->unit) {
            EnergyUnit::Litres => $this->amount,
            EnergyUnit::Gallons => $this->amount->multipliedBy(self::LITRES_PER_GALLON)->toScale($scale, BrickRoundingMode::HALF_UP),
            EnergyUnit::KilowattHours => throw InvalidDomainValueException::outOfRange('energy quantity in litres', 'kilowatt-hours'),
        };
    }

    /**
     * Value expressed in kilowatt-hours.
     */
    public function kilowattHours(int $scale = 6): BigDecimal
    {
        if (! $this->unit->isElectric()) {
            throw InvalidDomainValueException::outOfRange('energy quantity in kilowatt-hours', $this->unit->value);
        }

        return $this->amount->toScale($scale, BrickRoundingMode::HALF_UP);
    }

    public function format(int $scale = 3): string
    {
        return sprintf(
            '%s %s',
            number_format($this->amount->toFloat(), $scale, '.', ','),
            $this->unit->symbol(),
        );
    }

    public function jsonSerialize(): string
    {
        return (string) $this->amount;
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    private static function positive(string|int|float $amount, EnergyUnit $unit): self
    {
        $decimal = self::parse($amount);

        if ($decimal->isNegative()) {
            throw InvalidDomainValueException::negative('energy quantity', $amount);
        }

        return new self($decimal, $unit);
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->unit !== $other->unit) {
            throw InvalidDomainValueException::outOfRange(
                'energy quantity unit',
                sprintf('%s vs %s', $this->unit->value, $other->unit->value),
            );
        }
    }

    private static function parse(string|int|float|BigDecimal $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('energy quantity value', (string) $value);
        }
    }
}
