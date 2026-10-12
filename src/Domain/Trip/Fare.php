<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;

/**
 * What a platform pays the driver for a trip, before any cost.
 */
final class Fare
{
    public function __construct(
        public readonly Money $grossAmount,
        public readonly Distance $distance,
        public readonly ?int $durationMinutes = null,
    ) {}

    public static function create(
        Money $grossAmount,
        Distance $distance,
        ?int $durationMinutes = null,
    ): self {
        return new self($grossAmount, $distance, $durationMinutes);
    }

    /**
     * Amount the platform keeps as commission.
     */
    public function commission(Percentage $rate): Money
    {
        return $this->grossAmount->percentage($rate);
    }

    /**
     * Amount the driver keeps before operating costs.
     */
    public function netBeforeCosts(Percentage $commissionRate): Money
    {
        return $this->grossAmount->minus($this->commission($commissionRate));
    }

    /**
     * Gross revenue earned per kilometre of the trip.
     */
    public function grossPerKilometre(int $scale = 6): BigDecimal
    {
        if (! $this->distance->isPositive()) {
            return BigDecimal::zero();
        }

        return $this->grossAmount->toDecimal()->dividedBy(
            $this->distance->toDecimal(),
            $scale,
            RoundingMode::HALF_UP,
        );
    }

    /**
     * Gross revenue earned per minute connected, if duration is known.
     */
    public function grossPerMinute(int $scale = 6): ?BigDecimal
    {
        if ($this->durationMinutes === null || $this->durationMinutes <= 0) {
            return null;
        }

        return $this->grossAmount->toDecimal()->dividedBy(
            (string) $this->durationMinutes,
            $scale,
            RoundingMode::HALF_UP,
        );
    }
}
