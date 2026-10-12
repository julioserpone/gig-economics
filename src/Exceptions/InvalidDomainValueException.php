<?php

declare(strict_types=1);

namespace GigEconomics\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a value would break a domain invariant (negative distance,
 * negative energy amount, division by zero, invalid rate, etc.).
 */
final class InvalidDomainValueException extends InvalidArgumentException
{
    public static function negative(string $concept, string|int|float $value): self
    {
        return new self(sprintf(
            'The %s cannot be negative. Received: %s.',
            $concept,
            (string) $value,
        ));
    }

    public static function divisionByZero(string $concept): self
    {
        return new self(sprintf(
            'Cannot compute the %s because the divisor is zero.',
            $concept,
        ));
    }

    public static function outOfRange(string $concept, string|int|float $value): self
    {
        return new self(sprintf(
            'The %s is out of the allowed range. Received: %s.',
            $concept,
            (string) $value,
        ));
    }
}
