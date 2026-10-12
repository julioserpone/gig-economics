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
 * Immutable distance expressed in kilometres.
 */
final class Distance implements JsonSerializable, Stringable
{
    private const KM_PER_MILE = '1.609344';

    private function __construct(private readonly BigDecimal $kilometres) {}

    public static function ofKilometres(string|int|float|BigDecimal $kilometres): self
    {
        $decimal = $kilometres instanceof BigDecimal ? $kilometres : self::parse($kilometres);

        if ($decimal->isNegative()) {
            throw InvalidDomainValueException::negative('distance', (string) $decimal);
        }

        return new self($decimal);
    }

    public static function ofMiles(string|int|float $miles): self
    {
        return new self(
            self::parse($miles)->multipliedBy(self::KM_PER_MILE)->toScale(6, BrickRoundingMode::HALF_UP),
        );
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    /**
     * Difference between two odometer readings. A non-positive difference is a
     * data-quality signal, so the caller can discriminate it via `isPositive()`.
     */
    public static function betweenReadings(string|int|float $previous, string|int|float $current): self
    {
        return new self(self::parse($current)->minus(self::parse($previous)));
    }

    /**
     * Effective kilometres per unit of volume (km per gallon, km per litre...).
     */
    public function perUnit(string|int|float|BigDecimal $volume, int $scale = 4): BigDecimal
    {
        $divisor = $volume instanceof BigDecimal
            ? $volume
            : self::parse($volume);

        if ($divisor->isZero()) {
            throw InvalidDomainValueException::divisionByZero('energy economy');
        }

        return $this->kilometres->dividedBy($divisor, $scale, BrickRoundingMode::HALF_UP);
    }

    public function plus(self $other): self
    {
        return new self($this->kilometres->plus($other->kilometres));
    }

    public function minus(self $other): self
    {
        return new self($this->kilometres->minus($other->kilometres));
    }

    public function multipliedBy(string|int|float|BigDecimal $factor, int $scale = 6): self
    {
        $decimal = $factor instanceof BigDecimal
            ? $factor
            : self::parse($factor);

        return new self($this->kilometres->multipliedBy($decimal)->toScale($scale, BrickRoundingMode::HALF_UP));
    }

    public function isZero(): bool
    {
        return $this->kilometres->isZero();
    }

    public function isPositive(): bool
    {
        return $this->kilometres->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->kilometres->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->kilometres->isGreaterThan($other->kilometres);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->kilometres->isGreaterThanOrEqualTo($other->kilometres);
    }

    public function equals(self $other): bool
    {
        return $this->kilometres->isEqualTo($other->kilometres);
    }

    public function toDecimal(): BigDecimal
    {
        return $this->kilometres;
    }

    public function toFloat(): float
    {
        return $this->kilometres->toFloat();
    }

    public function round(int $scale = 2): self
    {
        return new self($this->kilometres->toScale($scale, BrickRoundingMode::HALF_UP));
    }

    public function format(int $scale = 2): string
    {
        return sprintf('%s km', number_format($this->kilometres->toFloat(), $scale, '.', ','));
    }

    public function jsonSerialize(): string
    {
        return (string) $this->kilometres;
    }

    public function __toString(): string
    {
        return (string) $this->kilometres;
    }

    private static function parse(string|int|float $value): BigDecimal
    {
        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('distance value', (string) $value);
        }
    }
}
