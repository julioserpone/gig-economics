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
 * Immutable fuel volume expressed in gallons.
 *
 * The source workbook works in US gallons; litres are supported for
 * platform-agnostic mileage (Uber, DiDi, inDrive, Lyft across regions).
 */
final class FuelVolume implements JsonSerializable, Stringable
{
    private const LITRES_PER_GALLON = '3.785411784';

    private function __construct(private readonly BigDecimal $gallons) {}

    public static function ofGallons(string|int|float $gallons): self
    {
        $decimal = self::parse($gallons);

        if ($decimal->isNegative()) {
            throw InvalidDomainValueException::negative('fuel volume', $gallons);
        }

        return new self($decimal);
    }

    public static function ofLitres(string|int|float $litres): self
    {
        return new self(
            self::parse($litres)->dividedBy(self::LITRES_PER_GALLON, 9, BrickRoundingMode::HALF_UP),
        );
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function litres(): BigDecimal
    {
        return $this->gallons->multipliedBy(self::LITRES_PER_GALLON)->toScale(6, BrickRoundingMode::HALF_UP);
    }

    public function plus(self $other): self
    {
        return new self($this->gallons->plus($other->gallons));
    }

    public function minus(self $other): self
    {
        return new self($this->gallons->minus($other->gallons));
    }

    public function isZero(): bool
    {
        return $this->gallons->isZero();
    }

    public function isPositive(): bool
    {
        return $this->gallons->isPositive();
    }

    public function toDecimal(): BigDecimal
    {
        return $this->gallons;
    }

    public function toFloat(): float
    {
        return $this->gallons->toFloat();
    }

    public function format(int $scale = 3): string
    {
        return sprintf('%s gal', number_format($this->gallons->toFloat(), $scale, '.', ','));
    }

    public function jsonSerialize(): string
    {
        return (string) $this->gallons;
    }

    public function __toString(): string
    {
        return (string) $this->gallons;
    }

    private static function parse(string|int|float $value): BigDecimal
    {
        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('fuel volume value', (string) $value);
        }
    }
}
