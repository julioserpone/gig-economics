<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

/**
 * Fuel blends relevant to ride-hailing operations.
 */
enum FuelType: string
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
}
