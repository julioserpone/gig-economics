<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

/**
 * Ride-hailing platforms the library ships presets for.
 *
 * The library is deliberately platform-agnostic: any other marketplace can be
 * modelled with `Platform::custom()` without touching the calculation engine.
 */
enum Platform: string
{
    case Uber = 'uber';
    case DiDi = 'didi';
    case InDrive = 'indrive';
    case Lyft = 'lyft';
    case Custom = 'custom';

    /**
     * Typical marketplace commission, expressed in percentage points.
     *
     * Defaults are indicative only and are meant to be overridden per driver
     * agreement; they exist so a first estimate can be produced out of the box.
     */
    public function defaultCommissionPercentage(): float
    {
        return match ($this) {
            self::Uber => 25.0,
            self::DiDi => 20.0,
            self::InDrive => 10.0,
            self::Lyft => 25.0,
            self::Custom => 0.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Uber => 'Uber',
            self::DiDi => 'DiDi',
            self::InDrive => 'inDrive',
            self::Lyft => 'Lyft',
            self::Custom => 'Custom platform',
        };
    }
}
