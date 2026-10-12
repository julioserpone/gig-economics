<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

/**
 * Decimal rounding modes mirroring the ones most commonly required by
 * money, price and efficiency calculations.
 */
enum RoundingMode: string
{
    case HalfUp = 'half_up';
    case HalfDown = 'half_down';
    case HalfEven = 'half_even';
    case Down = 'down';
    case Up = 'up';
}
