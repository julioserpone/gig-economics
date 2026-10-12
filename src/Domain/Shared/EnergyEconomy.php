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
 * Immutable efficiency: distance covered per unit of energy consumed.
 *
 * For a combustion vehicle that is kilometres per gallon or per litre; for an
 * electric vehicle it is kilometres per kilowatt-hour (km/kWh). The unit is
 * carried alongside the value so a single type serves both drivetrains and no
 * caller has to assume which one is in play.
 *
 * Zero or negative readings are never valid efficiencies; check `isUsable()`
 * before aggregating them.
 */
final class EnergyEconomy implements JsonSerializable, Stringable
{
    private const KM_PER_MILE = '1.609344';

    private const LITRES_PER_GALLON = '3.785411784';

    private function __construct(
        private readonly BigDecimal $kilometresPerUnit,
        public readonly EnergyUnit $unit,
    ) {}

    /**
     * Build a value expressed in an explicit unit, e.g. `EnergyEconomy::of('6.5', EnergyUnit::KilowattHours)`.
     */
    public static function of(string|int|float|BigDecimal $value, EnergyUnit $unit): self
    {
        return new self(self::parse($value), $unit);
    }

    /**
     * Build from kilometres per gallon (combustion vehicles).
     */
    public static function ofKilometresPerGallon(string|int|float|BigDecimal $value): self
    {
        return new self(self::parse($value), EnergyUnit::Gallons);
    }

    /**
     * Build from kilometres per litre (combustion vehicles).
     */
    public static function ofKilometresPerLitre(string|int|float $value): self
    {
        return new self(self::parse($value), EnergyUnit::Litres);
    }

    /**
     * Build from kilometres per kilowatt-hour (electric vehicles).
     */
    public static function ofKilometresPerKilowattHour(string|int|float|BigDecimal $value): self
    {
        return new self(self::parse($value), EnergyUnit::KilowattHours);
    }

    /**
     * Build from miles per gallon (US, combustion vehicles).
     */
    public static function ofMilesPerGallon(string|int|float $value): self
    {
        return new self(
            self::parse($value)->multipliedBy(self::KM_PER_MILE),
            EnergyUnit::Gallons,
        );
    }

    /**
     * Build from kilometres per kilowatt-hour.
     */
    public static function ofMilesPerKilowattHour(string|int|float $value): self
    {
        return new self(
            self::parse($value)->multipliedBy(self::KM_PER_MILE),
            EnergyUnit::KilowattHours,
        );
    }

    /**
     * Build from a travelled distance and the energy consumed to cover it.
     *
     * The resulting economy is expressed in the unit of the consumed energy,
     * so `Distance / EnergyQuantity::ofKilowattHours(...)` yields km/kWh.
     */
    public static function fromDistanceAndEnergy(Distance $distance, EnergyQuantity $energy): self
    {
        if (! $energy->isPositive()) {
            throw InvalidDomainValueException::divisionByZero('energy economy');
        }

        return new self(
            $distance->toDecimal()->dividedBy($energy->toDecimal(), 9, BrickRoundingMode::HALF_UP),
            $energy->unit,
        );
    }

    /**
     * Distances per unit of energy, in the economy's own unit.
     */
    public function kilometresPerUnit(): BigDecimal
    {
        return $this->kilometresPerUnit;
    }

    /**
     * Distance per gallon. Electric economies have no gallon reading, so the
     * call is a domain error there; use `kilometresPerKilowattHour()` instead.
     */
    public function kilometresPerGallon(int $scale = 9): BigDecimal
    {
        return match ($this->unit) {
            EnergyUnit::Gallons => $this->kilometresPerUnit,
            EnergyUnit::Litres => $this->kilometresPerUnit->multipliedBy(self::LITRES_PER_GALLON)->toScale($scale, BrickRoundingMode::HALF_UP),
            EnergyUnit::KilowattHours => throw InvalidDomainValueException::outOfRange('energy economy in km/gal', 'kilowatt-hours'),
        };
    }

    /**
     * Distance per litre. Electric economies have no litre reading, so the call
     * is a domain error there.
     */
    public function kilometresPerLitre(int $scale = 6): BigDecimal
    {
        return match ($this->unit) {
            EnergyUnit::Litres => $this->kilometresPerUnit,
            EnergyUnit::Gallons => $this->kilometresPerUnit->dividedBy(self::LITRES_PER_GALLON, $scale, BrickRoundingMode::HALF_UP),
            EnergyUnit::KilowattHours => throw InvalidDomainValueException::outOfRange('energy economy in km/L', 'kilowatt-hours'),
        };
    }

    /**
     * Distance per kilowatt-hour, the metric for electric vehicles.
     */
    public function kilometresPerKilowattHour(int $scale = 6): BigDecimal
    {
        if (! $this->unit->isElectric()) {
            throw InvalidDomainValueException::outOfRange('energy economy in km/kWh', $this->unit->value);
        }

        return $this->kilometresPerUnit->toScale($scale, BrickRoundingMode::HALF_UP);
    }

    public function milesPerGallon(int $scale = 6): BigDecimal
    {
        return $this->kilometresPerGallon()->dividedBy(self::KM_PER_MILE, $scale, BrickRoundingMode::HALF_UP);
    }

    /**
     * Litres consumed to travel 100 km, the metric used in most continental
     * markets. Meaningful only for liquid fuels.
     */
    public function litresPer100Kilometres(int $scale = 4): BigDecimal
    {
        if (! $this->isUsable()) {
            throw InvalidDomainValueException::divisionByZero('litres per 100 km');
        }

        return BigDecimal::of('100')
            ->dividedBy($this->kilometresPerLitre(), $scale, BrickRoundingMode::HALF_UP)
            ->toScale($scale, BrickRoundingMode::HALF_UP);
    }

    /**
     * Kilowatt-hours consumed to travel 100 km, the electric equivalent of the
     * litre-based figure manufacturers publish.
     */
    public function kilowattHoursPer100Kilometres(int $scale = 4): BigDecimal
    {
        if (! $this->isUsable()) {
            throw InvalidDomainValueException::divisionByZero('kilowatt-hours per 100 km');
        }

        return BigDecimal::of('100')
            ->dividedBy($this->kilometresPerKilowattHour(), $scale, BrickRoundingMode::HALF_UP)
            ->toScale($scale, BrickRoundingMode::HALF_UP);
    }

    /**
     * Distance obtainable from a given amount of energy at this economy.
     */
    public function distanceFor(EnergyQuantity $energy): Distance
    {
        if ($energy->unit !== $this->unit) {
            throw InvalidDomainValueException::outOfRange(
                'energy economy unit',
                sprintf('%s vs %s', $energy->unit->value, $this->unit->value),
            );
        }

        return Distance::ofKilometres(
            $this->kilometresPerUnit
                ->multipliedBy($energy->toDecimal())
                ->toScale(6, BrickRoundingMode::HALF_UP),
        );
    }

    /**
     * Energy needed to drive a given distance at this economy.
     */
    public function energyFor(Distance $distance, int $scale = 9): EnergyQuantity
    {
        if (! $this->isUsable()) {
            return EnergyQuantity::zero($this->unit);
        }

        return EnergyQuantity::of(
            $distance->toDecimal()->dividedBy($this->kilometresPerUnit, $scale, BrickRoundingMode::HALF_UP),
            $this->unit,
        );
    }

    /**
     * True when the reading is strictly positive and therefore meaningful.
     */
    public function isUsable(): bool
    {
        return $this->kilometresPerUnit->isPositive();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->kilometresPerUnit->isGreaterThan($other->kilometresPerUnit);
    }

    public function toFloat(): float
    {
        return $this->kilometresPerUnit->toFloat();
    }

    public function format(int $scale = 2): string
    {
        return sprintf(
            '%s km/%s',
            number_format($this->kilometresPerUnit->toFloat(), $scale, '.', ','),
            $this->unit->symbol(),
        );
    }

    public function jsonSerialize(): string
    {
        return (string) $this->kilometresPerUnit;
    }

    public function __toString(): string
    {
        return (string) $this->kilometresPerUnit;
    }

    private static function parse(string|int|float|BigDecimal $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('energy economy value', (string) $value);
        }
    }
}
