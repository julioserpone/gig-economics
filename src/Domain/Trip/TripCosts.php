<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;

/**
 * Itemized operating costs of a trip.
 *
 * Every component is optional so a driver can model only what they actually
 * pay for (fuel, depreciation, tolls, parking, cleaning, platform fees).
 */
final class TripCosts
{
    private function __construct(
        public readonly Money $fuel,
        public readonly Money $depreciation,
        public readonly Money $other,
    ) {}

    public static function create(
        ?Money $fuel = null,
        ?Money $depreciation = null,
        ?Money $other = null,
        ?Currency $currency = null,
    ): self {
        $currency = self::resolveCurrency($currency, $fuel, $depreciation, $other);

        return new self(
            $fuel ?? Money::zero($currency),
            $depreciation ?? Money::zero($currency),
            $other ?? Money::zero($currency),
        );
    }

    public static function empty(?Currency $currency = null): self
    {
        return self::create(currency: $currency);
    }

    private static function resolveCurrency(
        ?Currency $explicit,
        ?Money ...$amounts,
    ): Currency {
        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($amounts as $amount) {
            if ($amount !== null) {
                return $amount->currency;
            }
        }

        return Currency::usd();
    }

    public function total(): Money
    {
        return $this->fuel->plus($this->depreciation)->plus($this->other);
    }

    public function currency(): Currency
    {
        return $this->fuel->currency;
    }
}
