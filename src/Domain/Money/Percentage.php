<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Exceptions\InvalidDomainValueException;
use JsonSerializable;
use Stringable;

/**
 * Immutable percentage holding a decimal fraction (15% is stored as 0.15).
 */
final class Percentage implements JsonSerializable, Stringable
{
    private function __construct(private readonly BigDecimal $fraction) {}

    /**
     * Build from a percentage-point value, e.g. `Percentage::of(15)` = 15%.
     */
    public static function of(string|int|float $percent): self
    {
        try {
            $decimal = BigDecimal::of(is_float($percent) ? (string) $percent : $percent);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('percentage', (string) $percent);
        }

        return new self($decimal->dividedBy('100', 12, BrickRoundingMode::HALF_UP));
    }

    /**
     * Build from an already-normalized fraction, e.g. `Percentage::fromFraction('0.15')`.
     */
    public static function fromFraction(string|int|float $fraction): self
    {
        try {
            $decimal = BigDecimal::of(is_float($fraction) ? (string) $fraction : $fraction);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('percentage fraction', (string) $fraction);
        }

        return new self($decimal);
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    /**
     * Normalized fraction (0.15 for 15%).
     */
    public function asFraction(): BigDecimal
    {
        return $this->fraction;
    }

    /**
     * Percentage points (15 for 15%).
     */
    public function asPercentagePoints(int $scale = 6): BigDecimal
    {
        return $this->fraction->multipliedBy('100')->toScale($scale, BrickRoundingMode::HALF_UP);
    }

    public function toFloat(): float
    {
        return $this->fraction->toFloat();
    }

    public function isZero(): bool
    {
        return $this->fraction->isZero();
    }

    public function jsonSerialize(): string
    {
        return (string) $this->fraction;
    }

    public function __toString(): string
    {
        return (string) $this->fraction;
    }
}
