<?php

declare(strict_types=1);

namespace App\Application\Support;

/**
 * Common cache TTL constants in seconds.
 *
 * Use this trait to avoid magic numbers for cache durations.
 * All values are in seconds for PSR-16 SimpleCache compatibility.
 *
 * Usage: `use CacheTimesTrait; ... self::SEVEN_DAYS`
 */
trait CacheTimesTrait
{
    protected const int ONE_MINUTE = 60;

    protected const int FIVE_MINUTES = 300;

    protected const int ONE_HOUR = 3600;

    protected const int ONE_DAY = 86400;

    protected const int SEVEN_DAYS = 604800;

    protected const int THIRTY_DAYS = 2592000;
}
