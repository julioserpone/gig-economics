<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Energy;

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\EnergyUnit;
use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * Ordered collection of replenishment events for one vehicle.
 *
 * Works identically for combustion and electric vehicles: distance is the
 * difference between consecutive meter readings, and economy is that distance
 * divided by the energy taken on board at the later reading.
 *
 * Purchases without an odometer reading are kept (they still carry cost and
 * energy) but yield no distance.
 */
final class EnergyLog
{
    /** @var array<int, EnergyPurchase> */
    private array $purchases;

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function __construct(array $purchases)
    {
        $this->purchases = array_values($purchases);
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    public static function fromPurchases(array $purchases): self
    {
        return new self($purchases);
    }

    public function add(EnergyPurchase $purchase): self
    {
        return new self([...$this->purchases, $purchase]);
    }

    /**
     * Purchases ordered chronologically by odometer, then by date.
     *
     * @return array<int, EnergyPurchase>
     */
    public function chronologically(): array
    {
        $ordered = $this->purchases;

        usort($ordered, static function (EnergyPurchase $a, EnergyPurchase $b): int {
            $odometerComparison = ($a->odometer ?? PHP_INT_MAX) <=> ($b->odometer ?? PHP_INT_MAX);

            if ($odometerComparison !== 0) {
                return $odometerComparison;
            }

            return ($a->purchasedAt?->getTimestamp() ?? 0) <=> ($b->purchasedAt?->getTimestamp() ?? 0);
        });

        return $ordered;
    }

    /**
     * @return array<int, EnergyPurchase>
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
        $withOdometer = $this->purchasesWithOdometer();

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
     * Per-purchase economy, one entry per purchase that has a preceding
     * odometer reading and a positive travelled distance.
     *
     * @return array<int, EnergyEconomy>
     */
    public function economies(): array
    {
        $withOdometer = $this->purchasesWithOdometer();

        $economies = [];
        $previous = null;

        foreach ($withOdometer as $purchase) {
            $current = $purchase->odometer;

            if ($previous !== null && $current !== null) {
                $delta = Distance::betweenReadings($previous, $current);

                if ($delta->isPositive() && $purchase->energy->isPositive()) {
                    $economies[] = $purchase->economyFor($delta);
                }
            }

            $previous = $current;
        }

        return $economies;
    }

    /**
     * @return array<int, EnergyEconomy>
     */
    public function usableEconomies(): array
    {
        return array_values(array_filter(
            $this->economies(),
            static fn (EnergyEconomy $economy): bool => $economy->isUsable(),
        ));
    }

    /**
     * Sum of every travelled distance delta.
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
     * Sum of every purchased quantity of energy.
     *
     * All purchases must be in the same unit; mixing kilowatt-hours with a
     * liquid fuel is a domain error.
     */
    public function totalEnergy(?EnergyUnit $unit = null): EnergyQuantity
    {
        if ($this->purchases === []) {
            return EnergyQuantity::zero($unit ?? EnergyUnit::Gallons);
        }

        $first = $this->purchases[0]->energy->unit;
        $total = EnergyQuantity::zero($first);

        foreach ($this->purchases as $purchase) {
            $total = $total->plus($purchase->energy);
        }

        return $total;
    }

    /**
     * Total spend (including tax) across every purchase.
     */
    public function totalSpend(): Money
    {
        if ($this->purchases === []) {
            throw InvalidDomainValueException::outOfRange('energy log spend', 'empty log without currency');
        }

        $amounts = array_map(
            static fn (EnergyPurchase $purchase): Money => $purchase->total(),
            $this->purchases,
        );

        return Money::sum($amounts);
    }

    /**
     * Total spend before tax across every purchase.
     */
    public function totalSubtotal(): Money
    {
        if ($this->purchases === []) {
            throw InvalidDomainValueException::outOfRange('energy log subtotal', 'empty log without currency');
        }

        $amounts = array_map(
            static fn (EnergyPurchase $purchase): Money => $purchase->subtotal(),
            $this->purchases,
        );

        return Money::sum($amounts);
    }

    /**
     * Whether every purchase in the log replenished an electric vehicle.
     */
    public function isElectric(): bool
    {
        foreach ($this->purchases as $purchase) {
            if (! $purchase->isElectric()) {
                return false;
            }
        }

        return $this->purchases !== [];
    }

    public function isEmpty(): bool
    {
        return $this->purchases === [];
    }

    /**
     * @return array<int, EnergyPurchase>
     */
    private function purchasesWithOdometer(): array
    {
        return array_values(array_filter(
            $this->chronologically(),
            static fn (EnergyPurchase $purchase): bool => $purchase->hasOdometer(),
        ));
    }
}
