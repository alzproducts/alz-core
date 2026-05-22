<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

/**
 * Per-service circuit breakers for queue jobs.
 *
 * Wraps {@see ThrottlesExceptions} with consistent config:
 * 10 transient failures within 5 minutes triggers a cooldown.
 * Only activates on {@see TransientApiFailure} — permanent failures pass through.
 */
final class ServiceCircuitBreaker
{
    public static function shopwired(): ThrottlesExceptions
    {
        return self::create('shopwired');
    }

    public static function helpscout(): ThrottlesExceptions
    {
        return self::create('helpscout');
    }

    public static function linnworks(): ThrottlesExceptions
    {
        return self::create('linnworks');
    }

    public static function mixpanel(): ThrottlesExceptions
    {
        return self::create('mixpanel');
    }

    public static function reviewsio(): ThrottlesExceptions
    {
        return self::create('reviewsio');
    }

    public static function googleAds(): ThrottlesExceptions
    {
        return self::create('google-ads');
    }

    public static function bingAdsRest(): ThrottlesExceptions
    {
        return self::create('bing-ads-rest');
    }

    private static function create(string $serviceKey): ThrottlesExceptions
    {
        return (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
            ->by($serviceKey)
            ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure);
    }
}
