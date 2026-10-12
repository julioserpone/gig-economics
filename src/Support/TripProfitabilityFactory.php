<?php

declare(strict_types=1);

namespace GigEconomics\Support;

use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;

/**
 * Bridges the I/O-facing {@see Configuration} to the pure domain.
 *
 * The domain must never read configuration itself, so this small factory lives
 * in the `Support` layer and translates a {@see Configuration} into the plain
 * domain objects the calculation engine expects.
 */
final class TripProfitabilityFactory
{
    public function __construct(private readonly Configuration $configuration = new Configuration) {}

    public static function fromConfiguration(Configuration $configuration): self
    {
        return new self($configuration);
    }

    /**
     * A calculator whose default profit target comes from the configuration.
     */
    public function calculator(): TripProfitabilityCalculator
    {
        return TripProfitabilityCalculator::withDefaultTarget(
            Money::of($this->configuration->defaultTargetProfitPerKilometre),
        );
    }
}
