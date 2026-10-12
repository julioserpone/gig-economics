<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Fuel;

use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\FuelVolume;
use GigEconomics\Exceptions\InvalidDomainValueException;
use JsonSerializable;
use Stringable;

/**
 * Immutable price of one gallon of fuel, before or after tax.
 *
 * The workbook distinguishes three related figures:
 *  - `Valor Unitario`: unit value before IVA;
 *  - `Precio/galon`: invoiced price per gallon;
 *  - weighted price: `SUM(Monto) / SUM(Galones)`, which lives in the analytics layer.
 */
final class FuelPrice implements JsonSerializable, Stringable
{
    private function __construct(private readonly Money $perGallon) {}

    /**
     * Build from an amount per gallon.
     */
    public static function perGallon(string|int|float $amount, ?Currency $currency = null): self
    {
        return new self(Money::of($amount, $currency));
    }

    /**
     * Derive the unit price per gallon from a subtotal and its volume:
     * `Valor total / Galones`.
     */
    public static function fromSubtotal(Money $subtotal, FuelVolume $volume): self
    {
        if (! $volume->isPositive()) {
            throw InvalidDomainValueException::divisionByZero('unit fuel price');
        }

        return new self($subtotal->dividedBy($volume->toDecimal(), 6));
    }

    public function amount(): Money
    {
        return $this->perGallon;
    }

    public function currency(): Currency
    {
        return $this->perGallon->currency;
    }

    /**
     * Total cost of a given volume at this price.
     */
    public function costOf(FuelVolume $volume): Money
    {
        return $this->perGallon->multipliedBy($volume->toDecimal(), 9);
    }

    /**
     * Tax portion implied by a tax rate, e.g. 15% IVA.
     */
    public function taxFor(FuelVolume $volume, Percentage $taxRate): Money
    {
        return $this->costOf($volume)->percentage($taxRate);
    }

    public function toFloat(): float
    {
        return $this->perGallon->toFloat();
    }

    public function format(int $scale = 4): string
    {
        return sprintf('%s/gal', $this->perGallon->format($scale));
    }

    public function jsonSerialize(): string
    {
        return (string) $this->perGallon;
    }

    public function __toString(): string
    {
        return (string) $this->perGallon;
    }
}
