<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

/**
 * Unit in which a vehicle's energy is bought and stored.
 *
 * Combustion vehicles measure a physical volume (gallons or litres) while
 * electric vehicles measure electrical energy (kilowatt-hours). The unit is
 * what lets the same efficiency and cost logic serve both drivetrains without
 * guessing which one is in play.
 */
enum EnergyUnit: string
{
    case Gallons = 'gallons';
    case Litres = 'litres';
    case KilowattHours = 'kwh';

    /**
     * Whether the unit measures electrical energy rather than a liquid fuel.
     */
    public function isElectric(): bool
    {
        return $this === self::KilowattHours;
    }

    /**
     * Human readable label for dashboards.
     */
    public function label(): string
    {
        return match ($this) {
            self::Gallons => 'Gallons',
            self::Litres => 'Litres',
            self::KilowattHours => 'Kilowatt-hours',
        };
    }

    /**
     * Compact symbol used by formatted values, e.g. `12.50 kWh`.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Gallons => 'gal',
            self::Litres => 'L',
            self::KilowattHours => 'kWh',
        };
    }
}
