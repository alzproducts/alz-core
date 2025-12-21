<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Exceptions\ApiRateLimitException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Random\RandomException;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Throttle noisy exceptions to Sentry (1-in-10 sampling).
 *
 * Laravel still logs 100% of exceptions normally.
 * This only affects what gets sent to Sentry.
 */
final class SentryBeforeSendCallback
{
    /**
     * Exceptions throttled to 10% sampling rate.
     *
     * Uses `instanceof` check, so subclasses of these exceptions
     * are also throttled (e.g., a custom ServiceDownException extending
     * ExternalServiceUnavailableException would be sampled at 10%).
     *
     * @var array<class-string>
     */
    private const array THROTTLED_EXCEPTIONS = [
        ExternalServiceUnavailableException::class,
        ApiRateLimitException::class,
        ThrottleRequestsException::class,
    ];

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        foreach (self::THROTTLED_EXCEPTIONS as $class) {
            if ($exception instanceof $class) {
                // Sample 10% of transient failures
                // Fail-open: if CSPRNG is unavailable, send all events
                try {
                    return \random_int(1, 10) === 1 ? $event : null;
                } catch (RandomException) {
                    return $event;
                }
            }
        }

        return $event;
    }
}
