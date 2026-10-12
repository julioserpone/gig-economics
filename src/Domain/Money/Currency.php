<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Money;

use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * ISO 4217 currency descriptor with the number of decimal places used for
 * display rounding. Kept deliberately small: the library only needs to know
 * the code, subtle currency symbol and scale to keep amounts reproducible.
 */
final class Currency
{
    private const DEFAULT_SCALE = 2;

    private function __construct(
        public readonly string $code,
        public readonly string $symbol,
        public readonly int $scale,
    ) {}

    public static function of(string $code, string $symbol = '', int $scale = self::DEFAULT_SCALE): self
    {
        $normalized = strtoupper(trim($code));

        if ($normalized === '') {
            throw InvalidDomainValueException::outOfRange('currency code', $code);
        }

        if ($scale < 0 || $scale > 8) {
            throw InvalidDomainValueException::outOfRange('currency scale', $scale);
        }

        return new self($normalized, $symbol === '' ? $normalized : $symbol, $scale);
    }

    public static function usd(): self
    {
        return self::of('USD', '$', 2);
    }

    public function isSameAs(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
