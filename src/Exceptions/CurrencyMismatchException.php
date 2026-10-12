<?php

declare(strict_types=1);

namespace GigEconomics\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a monetary or decimal operation is attempted with operands of
 * different currencies or dimensionless scales that cannot be combined.
 */
final class CurrencyMismatchException extends InvalidArgumentException
{
    public static function forOperands(string $left, string $right): self
    {
        return new self(sprintf(
            'Cannot operate on money with different currencies: [%s] and [%s].',
            $left,
            $right,
        ));
    }
}
