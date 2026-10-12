<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Fuel;

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\FuelVolume;
use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * Ordered collection of refuelling events for one vehicle.
 *
 * Reproduces the workbook's window logic:
 *  - `Km Recorridos` = current odometer reading minus the previous reading;
 *  - `Km/Galón`      = travelled distance divided by the current volume.
 *
 * Purchases without an odometer reading are kept (they still carry cost and
 * volume) but yield no distance, exactly like the source table.
 */
final class FuelLog
{
    /** @var array<int, FuelPurchase> */
    private array $purchases;

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function __construct(array $purchases)
    {
        $this->purchases = array_values($purchases);
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    public static function fromPurchases(array $purchases): self
    {
        return new self($purchases);
    }

    public function add(FuelPurchase $purchase): self
    {
        return new self([...$this->purchases, $purchase]);
    }

    /**
     * Purchases ordered chronologically by odometer, then by date.
     *
     * @return array<int, FuelPurchase>
     */
    public function chronologically(): array
    {
        $ordered = $this->purchases;

        usort($ordered, static function (FuelPurchase $a, FuelPurchase $b): int {
            $odometerComparison = ($a->odometer ?? PHP_INT_MAX) <=> ($b->odometer ?? PHP_INT_MAX);

            if ($odometerComparison !== 0) {
                return $odometerComparison;
            }

            return ($a->purchasedAt?->getTimestamp() ?? 0) <=> ($b->purchasedAt?->getTimestamp() ?? 0);
        });

        return $ordered;
    }

    /**
     * @return array<int, FuelPurchase>
     */
    public function all(): array
    {
        return $this->purchases;
    }

    public function count(): int
    {
        return count($this->purchases);
    }

    /**
     * Distances travelled between consecutive odometer readings.
     *
     * Non-positive deltas (an odometer that goes backwards, a duplicated
     * reading) are dropped because they cannot represent real mileage.
     *
     * @return array<int, Distance>
     */
    public function travelledDistances(): array
    {
        $withOdometer = array_values(array_filter(
            $this->chronologically(),
            static fn (FuelPurchase $purchase): bool => $purchase->hasOdometer(),
        ));

        $distances = [];
        $previous = null;

        foreach ($withOdometer as $purchase) {
            $current = $purchase->odometer;

            if ($previous !== null && $current !== null) {
                $delta = Distance::betweenReadings($previous, $current);

                if ($delta->isPositive()) {
                    $distances[] = $delta;
                }
            }

            $previous = $current;
        }

        return $distances;
    }

    /**
     * Per-purchase efficiency (`Km/Galón`), one entry per purchase that has a
     * preceding odometer reading and a positive travelled distance.
     *
     * @return array<int, FuelEfficiency>
     */
    public function efficiencies(): array
    {
        $withOdometer = array_values(array_filter(
            $this->chronologically(),
            static fn (FuelPurchase $purchase): bool => $purchase->hasOdometer(),
        ));

        $efficiencies = [];
        $previous = null;

        foreach ($withOdometer as $purchase) {
            $current = $purchase->odometer;

            if ($previous !== null && $current !== null) {
                $delta = Distance::betweenReadings($previous, $current);

                if ($delta->isPositive() && $purchase->volume->isPositive()) {
                    $efficiencies[] = $purchase->efficiencyFor($delta);
                }
            }

            $previous = $current;
        }

        return $efficiencies;
    }

    /**
     * @return array<int, FuelEfficiency>
     */
    public function usableEfficiencies(): array
    {
        return array_values(array_filter(
            $this->efficiencies(),
            static fn (FuelEfficiency $efficiency): bool => $efficiency->isUsable(),
        ));
    }

    /**
     * Sum of every `Km Recorridos` delta.
     */
    public function totalDistance(): Distance
    {
        $total = Distance::zero();

        foreach ($this->travelledDistances() as $distance) {
            $total = $total->plus($distance);
        }

        return $total;
    }

    /**
     * Sum of every purchased volume.
     */
    public function totalVolume(): FuelVolume
    {
        $total = FuelVolume::zero();

        foreach ($this->purchases as $purchase) {
            $total = $total->plus($purchase->volume);
        }

        return $total;
    }

    /**
     * Total `Monto` (including tax) across every purchase.
     */
    public function totalSpend(): Money
    {
        if ($this->purchases === []) {
            throw InvalidDomainValueException::outOfRange('fuel log spend', 'empty log without currency');
        }

        $amounts = array_map(
            static fn (FuelPurchase $purchase): Money => $purchase->total(),
            $this->purchases,
        );

        return Money::sum($amounts);
    }

    /**
     * Total `Valor total` (subtotal before tax) across every purchase.
     */
    public function totalSubtotal(): Money
    {
        if ($this->purchases === []) {
            throw InvalidDomainValueException::outOfRange('fuel log subtotal', 'empty log without currency');
        }

        $amounts = array_map(
            static fn (FuelPurchase $purchase): Money => $purchase->subtotal(),
            $this->purchases,
        );

        return Money::sum($amounts);
    }

    public function isEmpty(): bool
    {
        return $this->purchases === [];
    }
}
