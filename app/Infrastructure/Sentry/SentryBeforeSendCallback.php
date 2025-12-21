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
    /** @var array<class-string> */
    private const array THROTTLED_EXCEPTIONS = [
        ExternalServiceUnavailableException::class,
        ApiRateLimitException::class,
        ThrottleRequestsException::class,
    ];

    /**
     * @throws RandomException
     */
    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        foreach (self::THROTTLED_EXCEPTIONS as $class) {
            if ($exception instanceof $class) {
                // Sample 10% of transient failures
                return \random_int(1, 10) === 1 ? $event : null;
            }
        }

        return $event;
    }
}
