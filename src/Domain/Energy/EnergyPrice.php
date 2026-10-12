<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Energy;

use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyUnit;
use GigEconomics\Exceptions\InvalidDomainValueException;
use JsonSerializable;
use Stringable;

/**
 * Immutable price of one unit of energy, before or after tax.
 *
 * A combustion driver pays per gallon or per litre; an electric driver pays
 * per kilowatt-hour. The unit is carried with the amount so `perUnit()` can be
 * built directly from a replenishment record and the same arithmetic works for
 * every drivetrain.
 *
 * Three related figures are distinguished:
 *  - the pre-tax unit value;
 *  - the invoiced price per unit;
 *  - the weighted price (total spend over total energy), which lives in the
 *    analytics layer.
 */
final class EnergyPrice implements JsonSerializable, Stringable
{
    private function __construct(
        private readonly Money $perUnit,
        public readonly EnergyUnit $unit = EnergyUnit::Gallons,
    ) {}

    /**
     * Build from an amount per gallon (combustion vehicles).
     */
    public static function perGallon(string|int|float $amount, ?Currency $currency = null): self
    {
        return new self(Money::of($amount, $currency), EnergyUnit::Gallons);
    }

    /**
     * Build from an amount per litre (combustion vehicles).
     */
    public static function perLitre(string|int|float $amount, ?Currency $currency = null): self
    {
        return new self(Money::of($amount, $currency), EnergyUnit::Litres);
    }

    /**
     * Build from an amount per kilowatt-hour (electric vehicles).
     */
    public static function perKilowattHour(string|int|float $amount, ?Currency $currency = null): self
    {
        return new self(Money::of($amount, $currency), EnergyUnit::KilowattHours);
    }

    /**
     * Derive the unit price from a subtotal and the amount of energy it bought,
     * i.e. `subtotal / energy`.
     */
    public static function fromSubtotal(Money $subtotal, EnergyQuantity $energy): self
    {
        if (! $energy->isPositive()) {
            throw InvalidDomainValueException::divisionByZero('unit energy price');
        }

        return new self(
            $subtotal->dividedBy($energy->toDecimal(), 6),
            $energy->unit,
        );
    }

    public function amount(): Money
    {
        return $this->perUnit;
    }

    public function currency(): Currency
    {
        return $this->perUnit->currency;
    }

    /**
     * Whether the price is per kilowatt-hour (an electric vehicle).
     */
    public function isElectric(): bool
    {
        return $this->unit->isElectric();
    }

    /**
     * Total cost of a given amount of energy at this price.
     */
    public function costOf(EnergyQuantity $energy): Money
    {
        if ($energy->unit !== $this->unit) {
            throw InvalidDomainValueException::outOfRange(
                'energy price unit',
                sprintf('%s vs %s', $this->unit->value, $energy->unit->value),
            );
        }

        return $this->perUnit->multipliedBy($energy->toDecimal(), 9);
    }

    /**
     * Tax portion implied by a tax rate applied to this purchase.
     */
    public function taxFor(EnergyQuantity $energy, Percentage $taxRate): Money
    {
        return $this->costOf($energy)->percentage($taxRate);
    }

    public function toFloat(): float
    {
        return $this->perUnit->toFloat();
    }

    public function format(int $scale = 4): string
    {
        return sprintf('%s/%s', $this->perUnit->format($scale), $this->unit->symbol());
    }

    public function jsonSerialize(): string
    {
        return (string) $this->perUnit;
    }

    public function __toString(): string
    {
        return (string) $this->perUnit;
    }
}
