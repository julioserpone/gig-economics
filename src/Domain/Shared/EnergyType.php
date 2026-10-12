<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

/**
 * The energy source a vehicle runs on.
 *
 * Combustion blends (gasoline, diesel, ethanol, LPG, CNG) are bought by volume,
 * while `Electric` is bought in kilowatt-hours. Each case knows the unit its
 * energy is measured in and whether it belongs to an electric drivetrain, so
 * cost and efficiency logic can branch on data instead of on caller convention.
 */
enum EnergyType: string
{
    case RegularGasoline = 'regular_gasoline';
    case PremiumGasoline = 'premium_gasoline';
    case Diesel = 'diesel';
    case Ethanol = 'ethanol';
    case Lpg = 'lpg';
    case Cng = 'cng';
    case Electric = 'electric';

    public function label(): string
    {
        return match ($this) {
            self::RegularGasoline => 'Regular gasoline',
            self::PremiumGasoline => 'Premium gasoline',
            self::Diesel => 'Diesel',
            self::Ethanol => 'Ethanol',
            self::Lpg => 'LPG',
            self::Cng => 'CNG',
            self::Electric => 'Electric',
        };
    }

    /**
     * The unit this energy source is purchased and measured in.
     */
    public function unit(): EnergyUnit
    {
        return match ($this) {
            self::Electric => EnergyUnit::KilowattHours,
            default => EnergyUnit::Gallons,
        };
    }

    /**
     * Whether the source powers an electric drivetrain.
     */
    public function isElectric(): bool
    {
        return $this === self::Electric;
    }
}
