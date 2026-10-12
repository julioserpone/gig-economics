<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Trip;

/**
 * Qualitative recommendation for a trip offer.
 */
enum TripVerdict: string
{
    case Accept = 'accept';
    case Marginal = 'marginal';
    case Reject = 'reject';

    /**
     * Derive a verdict from the profit-per-kilometre against a target.
     *
     * `marginalBand` is a relative tolerance: a profit within +/- that fraction
     * of the target is considered "marginal" rather than a clear accept/reject.
     */
    public static function fromProfitPerKilometre(
        float $profitPerKilometre,
        float $targetPerKilometre,
        float $marginalBand = 0.10,
    ): self {
        if ($targetPerKilometre <= 0.0) {
            return $profitPerKilometre > 0.0 ? self::Accept : self::Reject;
        }

        $lowerBound = $targetPerKilometre * (1 - $marginalBand);
        $upperBound = $targetPerKilometre * (1 + $marginalBand);

        return match (true) {
            $profitPerKilometre >= $upperBound => self::Accept,
            $profitPerKilometre >= $lowerBound => self::Marginal,
            default => self::Reject,
        };
    }

    public function isWorthTaking(): bool
    {
        return $this !== self::Reject;
    }

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Accept',
            self::Marginal => 'Marginal',
            self::Reject => 'Reject',
        };
    }
}
