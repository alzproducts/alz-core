<?php

declare(strict_types=1);

namespace App\Application\Linnworks\Enums;

use DateTimeImmutable;

/**
 * Redundancy tiers for Linnworks order synchronisation.
 *
 * Each tier defines a lookback window. All tiers share the same
 * SyncLinnworksOrdersUseCase — only the fromDate differs.
 * Wider tiers act as safety nets for missed orders.
 *
 * The cursor tier is handled separately by SyncLinnworksCursorUseCase.
 */
enum OrderSyncTier: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Full = 'full';

    /**
     * Calculate the lookback fromDate for this tier.
     *
     * IMPORTANT: Call in handle(), not constructor (Octane safety).
     */
    public function fromDate(): DateTimeImmutable
    {
        return match ($this) {
            self::Hourly => new DateTimeImmutable('-1 hour'),
            self::Daily => new DateTimeImmutable('-2 days'),
            self::Weekly => new DateTimeImmutable('-2 weeks'),
            self::Full => new DateTimeImmutable('-90 days'),
        };
    }
}
