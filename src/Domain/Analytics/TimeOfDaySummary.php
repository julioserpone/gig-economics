<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Analytics;

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\TimeOfDay;
use JsonSerializable;

/**
 * Spend grouped by time-of-day band.
 */
final class TimeOfDaySummary implements JsonSerializable
{
    public function __construct(
        public readonly TimeOfDay $band,
        public readonly int $purchaseCount,
        public readonly Money $totalSpend,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'band' => $this->band->value,
            'label' => $this->band->label(),
            'purchase_count' => $this->purchaseCount,
            'total_spend' => (string) $this->totalSpend,
        ];
    }
}
