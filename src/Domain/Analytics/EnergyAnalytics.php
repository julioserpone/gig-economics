<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Energy\EnergyPurchase;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\EnergyQuantity;
use GigEconomics\Domain\Shared\TimeOfDay;
use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * Analytical layer over an {@see EnergyLog}.
 *
 * Every figure is drivetrain-agnostic: a combustion log is measured in gallons
 * or litres and an electric log in kilowatt-hours, yet the same methods produce
 * the weighted price, the average economy, the monthly series, the vendor
 * ranking and the time-of-day breakdown for either.
 *
 *  - weighted price     -> total spend / total energy
 *  - average economy    -> mean of the strictly positive economy readings
 *  - monthly series     -> spend grouped by calendar month
 *  - vendor ranking     -> spend, weighted price and economy per vendor
 *  - time-of-day bands  -> spend grouped by 4 fixed bands
 */
final class EnergyAnalytics
{
    public function __construct(private readonly EnergyLog $log) {}

    public static function fromLog(EnergyLog $log): self
    {
        return new self($log);
    }

    /**
     * Headline KPIs for the whole log.
     */
    public function kpis(): EnergyKpis
    {
        $purchases = $this->log->all();

        if ($purchases === []) {
            throw InvalidDomainValueException::outOfRange('energy analytics', 'empty log');
        }

        return new EnergyKpis(
            purchaseCount: count($purchases),
            totalEnergy: $this->log->totalEnergy(),
            totalSpend: $this->log->totalSpend(),
            totalSubtotal: $this->log->totalSubtotal(),
            weightedPricePerUnit: $this->weightedPrice(),
            averageEconomy: $this->averageEconomy(),
            vendorCount: count($this->groupByVendorKey()),
            validEconomyCount: count($this->log->usableEconomies()),
            totalDistance: $this->log->totalDistance(),
            isElectric: $this->log->isElectric(),
        );
    }

    /**
     * The volume- or energy-weighted price per unit: total spend divided by
     * total energy.
     *
     * It is a ratio of sums, never an average of per-purchase prices, because
     * that would over-weight small purchases.
     */
    public function weightedPrice(): BigDecimal
    {
        $energy = $this->log->totalEnergy();

        if (! $energy->isPositive()) {
            throw InvalidDomainValueException::divisionByZero('weighted price per unit');
        }

        return $this->log->totalSpend()->toDecimal()->dividedBy(
            $energy->toDecimal(),
            6,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * The mean of the strictly positive economy readings only.
     */
    public function averageEconomy(): ?EnergyEconomy
    {
        $economies = $this->log->usableEconomies();

        if ($economies === []) {
            return null;
        }

        $sum = BigDecimal::zero();

        foreach ($economies as $economy) {
            $sum = $sum->plus($economy->kilometresPerUnit());
        }

        return EnergyEconomy::of(
            $sum->dividedBy((string) count($economies), 9, BrickRoundingMode::HALF_UP),
            $economies[0]->unit,
        );
    }

    /**
     * Spend grouped by calendar month, keyed by `YYYY-MM` and sorted ascending.
     *
     * @return array<int, PeriodSummary>
     */
    public function monthlySeries(): array
    {
        /** @var array<string, array<int, EnergyPurchase>> $groups */
        $groups = [];

        foreach ($this->log->all() as $purchase) {
            if ($purchase->purchasedAt === null) {
                continue;
            }

            $groups[$purchase->purchasedAt->format('Y-m')][] = $purchase;
        }

        ksort($groups);

        $summaries = [];

        foreach ($groups as $period => $purchases) {
            $summaries[] = new PeriodSummary(
                period: $period,
                purchaseCount: count($purchases),
                totalEnergy: $this->sumEnergy($purchases),
                totalSpend: $this->sumSpend($purchases),
                totalDistance: $this->sumDistance($purchases),
            );
        }

        return $summaries;
    }

    /**
     * Vendors ranked by total spend, highest first.
     *
     * @return array<int, VendorSummary>
     */
    public function vendorRanking(int $limit = 10): array
    {
        $groups = $this->groupByVendorKey();
        $summaries = [];

        foreach ($groups as $purchases) {
            $first = $purchases[0];

            $summaries[] = new VendorSummary(
                vendorName: $first->vendorName ?? 'Unknown vendor',
                vendorTaxId: $first->vendorTaxId,
                purchaseCount: count($purchases),
                totalEnergy: $this->sumEnergy($purchases),
                totalSpend: $this->sumSpend($purchases),
                weightedPricePerUnit: $this->weightedPriceOf($purchases),
                averageEconomy: $this->averageEconomyOf($purchases),
                validEconomyCount: $this->countValidEconomies($purchases),
            );
        }

        usort($summaries, static fn (VendorSummary $a, VendorSummary $b): int => $b->totalSpend->isGreaterThan($a->totalSpend) ? 1 : ($b->totalSpend->equals($a->totalSpend) ? 0 : -1));

        return array_slice($summaries, 0, $limit);
    }

    /**
     * Vendors ordered by weighted price, cheapest first.
     *
     * @return array<int, VendorSummary>
     */
    public function cheapestVendors(int $limit = 10): array
    {
        $ranked = $this->vendorRanking(PHP_INT_MAX);

        usort($ranked, static fn (VendorSummary $a, VendorSummary $b): int => $a->weightedPricePerUnit->isLessThan($b->weightedPricePerUnit) ? -1 : ($a->weightedPricePerUnit->isEqualTo($b->weightedPricePerUnit) ? 0 : 1));

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Vendors with the best (highest) average economy, requiring at least two
     * readings so a single value cannot dominate the ranking.
     *
     * @return array<int, VendorSummary>
     */
    public function bestEconomyVendors(int $limit = 10, int $minimumPurchases = 2): array
    {
        $ranked = array_values(array_filter(
            $this->vendorRanking(PHP_INT_MAX),
            static fn (VendorSummary $summary): bool => $summary->isRankableForEconomy($minimumPurchases),
        ));

        usort($ranked, static function (VendorSummary $a, VendorSummary $b): int {
            $left = $a->averageEconomy?->kilometresPerUnit();
            $right = $b->averageEconomy?->kilometresPerUnit();

            if ($left === null || $right === null) {
                return 0;
            }

            return $right->isGreaterThan($left) ? 1 : ($right->isEqualTo($left) ? 0 : -1);
        });

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Spend grouped by time-of-day band, always returning the four bands.
     *
     * @return array<int, TimeOfDaySummary>
     */
    public function timeOfDayBreakdown(): array
    {
        /** @var array<string, array<int, EnergyPurchase>> $groups */
        $groups = [];

        foreach ($this->log->all() as $purchase) {
            $band = $purchase->timeOfDay();

            if ($band === null) {
                continue;
            }

            $groups[$band->value][] = $purchase;
        }

        $summaries = [];

        foreach (TimeOfDay::cases() as $band) {
            $purchases = $groups[$band->value] ?? [];

            $summaries[] = new TimeOfDaySummary(
                band: $band,
                purchaseCount: count($purchases),
                totalSpend: $purchases === [] ? Money::zero() : $this->sumSpend($purchases),
            );
        }

        return $summaries;
    }

    /**
     * Share of total spend concentrated in the top N vendors.
     */
    public function spendConcentration(int $topN = 2): BigDecimal
    {
        $total = $this->log->totalSpend();

        if ($total->isZero()) {
            throw InvalidDomainValueException::divisionByZero('spend concentration');
        }

        $ranked = $this->vendorRanking($topN);
        $topSpend = Money::zero($total->currency);

        foreach ($ranked as $summary) {
            $topSpend = $topSpend->plus($summary->totalSpend);
        }

        return $topSpend->toDecimal()->dividedBy($total->toDecimal(), 6, BrickRoundingMode::HALF_UP);
    }

    /**
     * Effective cost per kilometre driven, using spend and distance.
     */
    public function costPerKilometre(int $scale = 6): ?BigDecimal
    {
        $distance = $this->log->totalDistance();

        if (! $distance->isPositive()) {
            return null;
        }

        return $this->log->totalSpend()->toDecimal()->dividedBy(
            $distance->toDecimal(),
            $scale,
            BrickRoundingMode::HALF_UP,
        );
    }

    public function energyLog(): EnergyLog
    {
        return $this->log;
    }

    /**
     * Group purchases by a stable vendor key (tax id first, then name).
     *
     * @return array<string, array<int, EnergyPurchase>>
     */
    private function groupByVendorKey(): array
    {
        /** @var array<string, array<int, EnergyPurchase>> $groups */
        $groups = [];

        foreach ($this->log->all() as $purchase) {
            $key = $purchase->vendorTaxId ?? $purchase->vendorName ?? 'unknown';
            $groups[$key][] = $purchase;
        }

        return $groups;
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function sumEnergy(array $purchases): EnergyQuantity
    {
        if ($purchases === []) {
            return EnergyQuantity::zero();
        }

        $total = EnergyQuantity::zero($purchases[0]->energy->unit);

        foreach ($purchases as $purchase) {
            $total = $total->plus($purchase->energy);
        }

        return $total;
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function sumSpend(array $purchases): Money
    {
        $amounts = array_map(
            static fn (EnergyPurchase $purchase): Money => $purchase->total(),
            $purchases,
        );

        return Money::sum($amounts);
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function sumDistance(array $purchases): Distance
    {
        return EnergyLog::fromPurchases($purchases)->totalDistance();
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function weightedPriceOf(array $purchases): BigDecimal
    {
        $energy = $this->sumEnergy($purchases);

        if (! $energy->isPositive()) {
            return BigDecimal::zero();
        }

        return $this->sumSpend($purchases)->toDecimal()->dividedBy(
            $energy->toDecimal(),
            6,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function averageEconomyOf(array $purchases): ?EnergyEconomy
    {
        $economies = EnergyLog::fromPurchases($purchases)->usableEconomies();

        if ($economies === []) {
            return null;
        }

        $sum = BigDecimal::zero();

        foreach ($economies as $economy) {
            $sum = $sum->plus($economy->kilometresPerUnit());
        }

        return EnergyEconomy::of(
            $sum->dividedBy((string) count($economies), 9, BrickRoundingMode::HALF_UP),
            $economies[0]->unit,
        );
    }

    /**
     * @param array<int, EnergyPurchase> $purchases
     */
    private function countValidEconomies(array $purchases): int
    {
        return count(EnergyLog::fromPurchases($purchases)->usableEconomies());
    }
}
