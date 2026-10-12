<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Quality;

/**
 * Categories of data-quality problems found in an energy log.
 */
enum DataQualityIssueType: string
{
    case MissingDate = 'missing_date';
    case MissingOdometer = 'missing_odometer';
    case NonPositiveDistance = 'non_positive_distance';
    case NonPositiveEnergy = 'non_positive_energy';
    case NonPositiveEconomy = 'non_positive_economy';
    case OdometerNotMonotonic = 'odometer_not_monotonic';
    case StatisticalOutlier = 'statistical_outlier';
}
