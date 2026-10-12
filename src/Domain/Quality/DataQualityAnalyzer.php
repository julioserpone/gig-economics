<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Quality;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;
use GigEconomics\Domain\Fuel\FuelLog;
use GigEconomics\Domain\Fuel\FuelPurchase;
use GigEconomics\Domain\Shared\FuelEfficiency;

/**
 * Inspects a {@see FuelLog} for the quality problems the workbook flags:
 *
 *  - rows without a date (the source table reserves 7 empty rows);
 *  - odometer readings that are missing, duplicated or go backwards;
 *  - non-positive distances and volumes that would corrupt efficiency;
 *  - statistical outliers among usable efficiency readings (median + MAD).
 */
final class DataQualityAnalyzer
{
    public function __construct(private readonly FuelLog $log) {}

    /**
     * @return array<int, DataQualityIssue>
     */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->log->all() as $index => $purchase) {
            $issues = [...$issues, ...$this->inspect($purchase, $index)];
        }

        return [...$issues, ...$this->outlierIssues()];
    }

    /**
     * @return array<int, DataQualityIssue>
     */
    public function issuesOf(Severity $severity): array
    {
        return array_values(array_filter(
            $this->issues(),
            static fn (DataQualityIssue $issue): bool => $issue->severity === $severity,
        ));
    }

    public function hasErrors(): bool
    {
        return $this->issuesOf(Severity::Error) !== [];
    }

    /**
     * A single 0..1 score where 1 means "no issue at all".
     */
    public function qualityScore(): BigDecimal
    {
        $count = $this->log->count();

        if ($count === 0) {
            return BigDecimal::of('0');
        }

        $penalty = 0.0;

        foreach ($this->issues() as $issue) {
            $penalty += match ($issue->severity) {
                Severity::Error => 1.0,
                Severity::Warning => 0.5,
                Severity::Info => 0.2,
            };
        }

        $score = max(0.0, 1 - ($penalty / $count));

        return BigDecimal::of((string) $score)->toScale(4, BrickRoundingMode::HALF_UP);
    }

    /**
     * Efficiency readings flagged as outliers via the median absolute
     * deviation, a robust alternative to mean/standard deviation.
     *
     * @return array<int, FuelEfficiency>
     */
    public function outlierEfficiencies(float $threshold = 3.5): array
    {
        $values = array_map(
            static fn (FuelEfficiency $efficiency): float => $efficiency->toFloat(),
            $this->log->usableEfficiencies(),
        );

        if (count($values) < 4) {
            return [];
        }

        $median = $this->median($values);
        $deviations = array_map(static fn (float $value): float => abs($value - $median), $values);
        $mad = $this->median($deviations);

        if ($mad <= 0.0) {
            return [];
        }

        $outliers = [];

        foreach ($this->log->usableEfficiencies() as $efficiency) {
            $modifiedZ = 0.6745 * (($efficiency->toFloat() - $median) / $mad);

            if (abs($modifiedZ) > $threshold) {
                $outliers[] = $efficiency;
            }
        }

        return $outliers;
    }

    /**
     * @return array<int, DataQualityIssue>
     */
    private function inspect(FuelPurchase $purchase, int $index): array
    {
        $issues = [];

        if ($purchase->purchasedAt === null) {
            $issues[] = new DataQualityIssue(
                DataQualityIssueType::MissingDate,
                Severity::Warning,
                'The refuelling record has no date, so it cannot be placed on a timeline.',
                $index,
                $purchase->reference,
            );
        }

        if (! $purchase->hasOdometer()) {
            $issues[] = new DataQualityIssue(
                DataQualityIssueType::MissingOdometer,
                Severity::Info,
                'The odometer reading is missing, so no distance can be derived.',
                $index,
                $purchase->reference,
            );
        }

        if (! $purchase->volume->isPositive()) {
            $issues[] = new DataQualityIssue(
                DataQualityIssueType::NonPositiveVolume,
                Severity::Error,
                'The fuelled volume is zero or negative.',
                $index,
                $purchase->reference,
            );
        }

        return $issues;
    }

    /**
     * @return array<int, DataQualityIssue>
     */
    private function outlierIssues(): array
    {
        $issues = [];

        foreach ($this->outlierEfficiencies() as $efficiency) {
            $issues[] = new DataQualityIssue(
                DataQualityIssueType::StatisticalOutlier,
                Severity::Warning,
                sprintf(
                    'The efficiency reading %s looks like an outlier against the rest of the log.',
                    $efficiency->format(2),
                ),
            );
        }

        return $issues;
    }

    /**
     * @param array<int, float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
