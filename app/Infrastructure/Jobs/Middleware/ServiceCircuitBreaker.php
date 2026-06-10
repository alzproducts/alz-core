<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\TransientApiFailure;
use Closure;
use Illuminate\Cache\RateLimiter;

/**
 * Per-service circuit breakers for queue jobs.
 *
 * Counts {@see TransientApiFailure} occurrences per service key. When the
 * threshold is reached (10 within 5 minutes), the job is released until
 * the cooldown expires. Below the threshold, exceptions rethrow so the
 * Worker's own $backoff / $maxExceptions apply normally.
 */
final class ServiceCircuitBreaker
{
    private const int MAX_FAILURES = 10;

    private const int DECAY_SECONDS = 300;

    private function __construct(
        private readonly string $serviceKey,
    ) {}

    public static function shopwired(): self
    {
        return new self('shopwired');
    }

    public static function helpscout(): self
    {
        return new self('helpscout');
    }

    public static function linnworks(): self
    {
        return new self('linnworks');
    }

    public static function mixpanel(): self
    {
        return new self('mixpanel');
    }

    public static function reviewsio(): self
    {
        return new self('reviewsio');
    }

    public static function googleAds(): self
    {
        return new self('google-ads');
    }

    public static function bingAdsRest(): self
    {
        return new self('bing-ads-rest');
    }

    /**
     * @throws TransientApiFailure
     */
    public function handle(object $job, Closure $next): void
    {
        $limiter = \app(RateLimiter::class);
        $key = 'service_circuit_breaker:' . $this->serviceKey;

        if ($limiter->tooManyAttempts($key, self::MAX_FAILURES)) {
            $job->release($limiter->availableIn($key) + 3);

            return;
        }

        try {
            $next($job);
        } catch (TransientApiFailure $e) {
            $limiter->hit($key, self::DECAY_SECONDS);

            throw $e;
        }
    }
}
