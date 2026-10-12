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
 * Immutable fuel efficiency: distance per unit of volume (km/gal, km/L).
 *
 * Values that are zero or negative are never considered valid efficiency
 * readings; use `isUsable()` before aggregating them.
 */
final class FuelEfficiency implements JsonSerializable, Stringable
{
    private const KM_PER_MILE = '1.609344';

    private const LITRES_PER_GALLON = '3.785411784';

    private function __construct(private readonly BigDecimal $kilometresPerGallon) {}

    /**
     * Build from kilometres per gallon (the unit used by the source workbook).
     */
    public static function ofKilometresPerGallon(string|int|float|BigDecimal $value): self
    {
        return new self(self::parse($value));
    }

    /**
     * Build from kilometres per litre.
     */
    public static function ofKilometresPerLitre(string|int|float $value): self
    {
        return new self(
            self::parse($value)->multipliedBy(self::LITRES_PER_GALLON)->toScale(9, BrickRoundingMode::HALF_UP),
        );
    }

    /**
     * Build from miles per gallon (US).
     */
    public static function ofMilesPerGallon(string|int|float $value): self
    {
        return new self(
            self::parse($value)->multipliedBy(self::KM_PER_MILE)->toScale(9, BrickRoundingMode::HALF_UP),
        );
    }

    /**
     * Build from kilometres and gallons, i.e. `Km Recorridos / Galones`.
     */
    public static function fromDistanceAndVolume(Distance $distance, FuelVolume $volume): self
    {
        if ($volume->isZero()) {
            throw InvalidDomainValueException::divisionByZero('fuel efficiency');
        }

        return new self(
            $distance->toDecimal()->dividedBy($volume->toDecimal(), 9, BrickRoundingMode::HALF_UP),
        );
    }

    public function kilometresPerGallon(): BigDecimal
    {
        return $this->kilometresPerGallon;
    }

    public function kilometresPerLitre(int $scale = 6): BigDecimal
    {
        return $this->kilometresPerGallon->dividedBy(self::LITRES_PER_GALLON, $scale, BrickRoundingMode::HALF_UP);
    }

    public function milesPerGallon(int $scale = 6): BigDecimal
    {
        return $this->kilometresPerGallon->dividedBy(self::KM_PER_MILE, $scale, BrickRoundingMode::HALF_UP);
    }

    /**
     * Litres consumed to travel 100 km, the metric used in most DiDi/inDrive markets.
     */
    public function litresPer100Kilometres(int $scale = 4): BigDecimal
    {
        if (! $this->isUsable()) {
            throw InvalidDomainValueException::divisionByZero('litres per 100 km');
        }

        return BigDecimal::of('100')
            ->dividedBy($this->kilometresPerGallon, $scale, BrickRoundingMode::HALF_UP)
            ->multipliedBy(self::LITRES_PER_GALLON)
            ->toScale($scale, BrickRoundingMode::HALF_UP);
    }

    /**
     * Distance obtainable from a given volume at this efficiency.
     */
    public function distanceFor(FuelVolume $volume): Distance
    {
        return Distance::ofKilometres(
            $this->kilometresPerGallon
                ->multipliedBy($volume->toDecimal())
                ->toScale(6, BrickRoundingMode::HALF_UP),
        );
    }

    /**
     * Effective efficiency once the working efficiency is known.
     *
     * Only strictly positive readings are meaningful: an empty, zero or
     * negative value is a quality signal, not an efficiency result.
     */
    public function isUsable(): bool
    {
        return $this->kilometresPerGallon->isPositive();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->kilometresPerGallon->isGreaterThan($other->kilometresPerGallon);
    }

    public function toFloat(): float
    {
        return $this->kilometresPerGallon->toFloat();
    }

    public function format(int $scale = 2): string
    {
        return sprintf('%s km/gal', number_format($this->kilometresPerGallon->toFloat(), $scale, '.', ','));
    }

    public function jsonSerialize(): string
    {
        return (string) $this->kilometresPerGallon;
    }

    public function __toString(): string
    {
        return (string) $this->kilometresPerGallon;
    }

    private static function parse(string|int|float|BigDecimal $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('fuel efficiency value', (string) $value);
        }
    }
}
