<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Quality;

/**
 * Categories of data-quality problems found in a fuel log.
 */
enum DataQualityIssueType: string
{
    case MissingDate = 'missing_date';
    case MissingOdometer = 'missing_odometer';
    case NonPositiveDistance = 'non_positive_distance';
    case NonPositiveVolume = 'non_positive_volume';
    case NonPositiveEfficiency = 'non_positive_efficiency';
    case OdometerNotMonotonic = 'odometer_not_monotonic';
    case StatisticalOutlier = 'statistical_outlier';
}
