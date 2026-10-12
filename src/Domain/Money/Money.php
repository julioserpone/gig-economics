<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Domain\Shared\RoundingMode;
use GigEconomics\Exceptions\CurrencyMismatchException;
use GigEconomics\Exceptions\InvalidDomainValueException;
use JsonSerializable;
use Stringable;

/**
 * Immutable monetary amount backed by an arbitrary-precision decimal.
 *
 * No IEEE-754 float is ever used for value arithmetic, so
 * 0.1 + 0.2 === 0.3 holds and invoices reproduce to the cent.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        private readonly BigDecimal $amount,
        public readonly Currency $currency,
    ) {}

    /**
     * Build a monetary amount from a decimal string, int or float.
     */
    public static function of(string|int|float $amount, ?Currency $currency = null): self
    {
        try {
            $decimal = BigDecimal::of(is_float($amount) ? (string) $amount : $amount);
        } catch (NumberFormatException|MathException) {
            throw InvalidDomainValueException::outOfRange('money amount', (string) $amount);
        }

        return new self($decimal, $currency ?? Currency::usd());
    }

    public static function zero(?Currency $currency = null): self
    {
        return self::of('0', $currency);
    }

    /**
     * Sum a list of amounts. An empty list collapses to zero only when an
     * explicit currency is provided, otherwise it is an invalid operation.
     *
     * @param array<int, self> $amounts
     */
    public static function sum(array $amounts, ?Currency $currency = null): self
    {
        if ($amounts === []) {
            if ($currency === null) {
                throw InvalidDomainValueException::outOfRange('money sum', 'empty list without currency');
            }

            return self::zero($currency);
        }

        $total = array_shift($amounts);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Ratio of two amounts, e.g. the weighted energy price (total spend divided
     * by total energy).
     */
    public function divideBy(self $divisor, int $scale = 6): BigDecimal
    {
        $this->assertSameCurrency($divisor);

        if ($divisor->isZero()) {
            throw InvalidDomainValueException::divisionByZero('weighted unit price');
        }

        return $this->amount->dividedBy($divisor->amount, $scale, BrickRoundingMode::HALF_UP);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function multipliedBy(string|int|float|BigDecimal $factor, int $scale = 12): self
    {
        $decimal = self::decimalFrom($factor);

        return new self(
            $this->amount->multipliedBy($decimal)->toScale($scale, BrickRoundingMode::HALF_UP),
            $this->currency,
        );
    }

    public function percentage(Percentage $percentage, int $scale = 12): self
    {
        return $this->multipliedBy($percentage->asFraction(), $scale);
    }

    public function dividedBy(string|int|float|BigDecimal $divisor, int $scale = 12): self
    {
        $decimal = self::decimalFrom($divisor);

        if ($decimal->isZero()) {
            throw InvalidDomainValueException::divisionByZero('money division');
        }

        return new self(
            $this->amount->dividedBy($decimal, $scale, BrickRoundingMode::HALF_UP),
            $this->currency,
        );
    }

    /**
     * Return a copy rounded to the given scale for presentation. The original
     * amount is untouched, so repeated rounding never compounds error.
     */
    public function roundedTo(int $scale = 2, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->amount->toScale($scale, self::toBrickRoundingMode($mode)), $this->currency);
    }

    public function absolute(): self
    {
        return new self($this->amount->abs(), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
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
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThanOrEqualTo($other->amount);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isLessThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency->isSameAs($other->currency)
            && $this->amount->isEqualTo($other->amount);
    }

    public function max(self $other): self
    {
        return $this->isGreaterThanOrEqualTo($other) ? $this : $other;
    }

    public function min(self $other): self
    {
        return $this->isLessThan($other) ? $this : $other;
    }

    /**
     * Raw decimal value, useful for downstream math without losing precision.
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
     * Format for display using the currency scale and symbol.
     */
    public function format(?int $scale = null, bool $withSymbol = true): string
    {
        $scale ??= $this->currency->scale;
        $rounded = $this->amount->toScale($scale, BrickRoundingMode::HALF_UP);
        $number = number_format($rounded->toFloat(), $scale, '.', ',');

        return $withSymbol
            ? sprintf('%s%s', $this->currency->symbol, $number)
            : $number;
    }

    public function jsonSerialize(): string
    {
        return (string) $this->amount;
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->isSameAs($other->currency)) {
            throw CurrencyMismatchException::forOperands(
                $this->currency->code,
                $other->currency->code,
            );
        }
    }

    private static function decimalFrom(string|int|float|BigDecimal $value): BigDecimal
    {
        return $value instanceof BigDecimal
            ? $value
            : BigDecimal::of(is_float($value) ? (string) $value : $value);
    }

    private static function toBrickRoundingMode(RoundingMode $mode): BrickRoundingMode
    {
        return match ($mode) {
            RoundingMode::HalfUp => BrickRoundingMode::HALF_UP,
            RoundingMode::HalfDown => BrickRoundingMode::HALF_DOWN,
            RoundingMode::HalfEven => BrickRoundingMode::HALF_EVEN,
            RoundingMode::Down => BrickRoundingMode::DOWN,
            RoundingMode::Up => BrickRoundingMode::UP,
        };
    }
}
