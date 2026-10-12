<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Domain\Fuel\FuelLog;
use GigEconomics\Domain\Fuel\FuelPurchase;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\FuelEfficiency;
use GigEconomics\Domain\Shared\FuelVolume;
use GigEconomics\Domain\Shared\TimeOfDay;
use GigEconomics\Exceptions\InvalidDomainValueException;

/**
 * Analytical layer over a {@see FuelLog}.
 *
 * Every figure reproduces a formula from the source workbook:
 *  - weighted price     -> SUM(Monto) / SUM(Galones)
 *  - average efficiency -> AVERAGEIF(Km/Galon, ">0")
 *  - monthly series     -> spend grouped by calendar month
 *  - vendor ranking     -> spend, weighted price and efficiency per vendor
 *  - time-of-day bands  -> spend grouped by 4 fixed bands
 */
final class FuelAnalytics
{
    public function __construct(private readonly FuelLog $log) {}

    public function fromLog(FuelLog $log): self
    {
        return new self($log);
    }

    /**
     * Headline KPIs for the whole log.
     */
    public function kpis(): FuelKpis
    {
        $purchases = $this->log->all();

        if ($purchases === []) {
            throw InvalidDomainValueException::outOfRange('fuel analytics', 'empty log');
        }

        $totalSpend = $this->log->totalSpend();
        $totalSubtotal = $this->log->totalSubtotal();
        $totalVolume = $this->log->totalVolume();

        return new FuelKpis(
            purchaseCount: count($purchases),
            totalVolume: $totalVolume,
            totalSpend: $totalSpend,
            totalSubtotal: $totalSubtotal,
            weightedPricePerGallon: $this->weightedPrice(),
            averageEfficiency: $this->averageEfficiency(),
            vendorCount: count($this->groupByVendorKey()),
            validEfficiencyCount: count($this->log->usableEfficiencies()),
            totalDistance: $this->log->totalDistance(),
        );
    }

    /**
     * `SUM(Monto) / SUM(Galones)`: the volume-weighted price per gallon.
     *
     * It is a ratio of sums, never an average of per-purchase prices, because
     * that would over-weight small refuels.
     */
    public function weightedPrice(): BigDecimal
    {
        $volume = $this->log->totalVolume();

        if (! $volume->isPositive()) {
            throw InvalidDomainValueException::divisionByZero('weighted price per gallon');
        }

        return $this->log->totalSpend()->toDecimal()->dividedBy(
            $volume->toDecimal(),
            6,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * `AVERAGEIF(Km/Galon, ">0")`: average of usable efficiencies only.
     */
    public function averageEfficiency(): ?FuelEfficiency
    {
        $efficiencies = $this->log->usableEfficiencies();

        if ($efficiencies === []) {
            return null;
        }

        $sum = BigDecimal::zero();

        foreach ($efficiencies as $efficiency) {
            $sum = $sum->plus($efficiency->kilometresPerGallon());
        }

        return FuelEfficiency::ofKilometresPerGallon(
            $sum->dividedBy((string) count($efficiencies), 9, BrickRoundingMode::HALF_UP),
        );
    }

    /**
     * Spend grouped by calendar month, keyed by `YYYY-MM` and sorted ascending.
     *
     * @return array<int, PeriodSummary>
     */
    public function monthlySeries(): array
    {
        /** @var array<string, array<int, FuelPurchase>> $groups */
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
                totalVolume: $this->sumVolume($purchases),
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
                totalVolume: $this->sumVolume($purchases),
                totalSpend: $this->sumSpend($purchases),
                weightedPricePerGallon: $this->weightedPriceOf($purchases),
                averageEfficiency: $this->averageEfficiencyOf($purchases),
                validEfficiencyCount: $this->countValidEfficiencies($purchases),
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

        usort($ranked, static fn (VendorSummary $a, VendorSummary $b): int => $a->weightedPricePerGallon->isLessThan($b->weightedPricePerGallon) ? -1 : ($a->weightedPricePerGallon->isEqualTo($b->weightedPricePerGallon) ? 0 : 1));

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Vendors with the best (highest) average efficiency, requiring at least
     * two refuels so a single reading cannot dominate the ranking.
     *
     * @return array<int, VendorSummary>
     */
    public function bestEfficiencyVendors(int $limit = 10, int $minimumPurchases = 2): array
    {
        $ranked = array_values(array_filter(
            $this->vendorRanking(PHP_INT_MAX),
            static fn (VendorSummary $summary): bool => $summary->isRankableForEfficiency($minimumPurchases),
        ));

        usort($ranked, static function (VendorSummary $a, VendorSummary $b): int {
            $left = $a->averageEfficiency?->kilometresPerGallon();
            $right = $b->averageEfficiency?->kilometresPerGallon();

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
        /** @var array<string, array<int, FuelPurchase>> $groups */
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
     *
     * The workbook reports the top-2 concentration as `0.826...`; that is the
     * ratio of the two largest vendor spends to total spend.
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

    public function fuelLog(): FuelLog
    {
        return $this->log;
    }

    /**
     * Group purchases by a stable vendor key (tax id first, then name).
     *
     * @return array<string, array<int, FuelPurchase>>
     */
    private function groupByVendorKey(): array
    {
        /** @var array<string, array<int, FuelPurchase>> $groups */
        $groups = [];

        foreach ($this->log->all() as $purchase) {
            $key = $purchase->vendorTaxId ?? $purchase->vendorName ?? 'unknown';
            $groups[$key][] = $purchase;
        }

        return $groups;
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function sumVolume(array $purchases): FuelVolume
    {
        $total = FuelVolume::zero();

        foreach ($purchases as $purchase) {
            $total = $total->plus($purchase->volume);
        }

        return $total;
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function sumSpend(array $purchases): Money
    {
        $amounts = array_map(
            static fn (FuelPurchase $purchase): Money => $purchase->total(),
            $purchases,
        );

        return Money::sum($amounts);
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function sumDistance(array $purchases): Distance
    {
        return FuelLog::fromPurchases($purchases)->totalDistance();
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function weightedPriceOf(array $purchases): BigDecimal
    {
        $volume = $this->sumVolume($purchases);

        if (! $volume->isPositive()) {
            return BigDecimal::zero();
        }

        return $this->sumSpend($purchases)->toDecimal()->dividedBy(
            $volume->toDecimal(),
            6,
            BrickRoundingMode::HALF_UP,
        );
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function averageEfficiencyOf(array $purchases): ?FuelEfficiency
    {
        $efficiencies = FuelLog::fromPurchases($purchases)->usableEfficiencies();

        if ($efficiencies === []) {
            return null;
        }

        $sum = BigDecimal::zero();

        foreach ($efficiencies as $efficiency) {
            $sum = $sum->plus($efficiency->kilometresPerGallon());
        }

        return FuelEfficiency::ofKilometresPerGallon(
            $sum->dividedBy((string) count($efficiencies), 9, BrickRoundingMode::HALF_UP),
        );
    }

    /**
     * @param array<int, FuelPurchase> $purchases
     */
    private function countValidEfficiencies(array $purchases): int
    {
        return count(FuelLog::fromPurchases($purchases)->usableEfficiencies());
    }
}
