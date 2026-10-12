<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Shared;

use DateTimeInterface;

/**
 * Time-of-day bands used for energy and earnings analytics.
 *
 * Four fixed bands are used so any log can be compared on a like-for-like
 * basis: dawn 00:00–05:59, morning 06:00–11:59, afternoon 12:00–17:59 and
 * night 18:00–23:59.
 */
enum TimeOfDay: string
{
    case Dawn = 'dawn';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Night = 'night';

    /**
     * Resolve the band from a 24-hour clock time.
     */
    public static function fromHour(int $hour): self
    {
        return match (true) {
            $hour >= 0 && $hour <= 5 => self::Dawn,
            $hour >= 6 && $hour <= 11 => self::Morning,
            $hour >= 12 && $hour <= 17 => self::Afternoon,
            default => self::Night,
        };
    }

    public static function fromDateTime(DateTimeInterface $moment): self
    {
        return self::fromHour((int) $moment->format('G'));
    }

    /**
     * Human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Dawn => 'Dawn (00:00-05:59)',
            self::Morning => 'Morning (06:00-11:59)',
            self::Afternoon => 'Afternoon (12:00-17:59)',
            self::Night => 'Night (18:00-23:59)',
        };
    }
}
