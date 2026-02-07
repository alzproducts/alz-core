<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Retryable API failure with optional backoff hint.
 *
 * Concrete exceptions extending this class represent transient conditions
 * that may resolve by retrying (rate limits, service outages, timeouts).
 *
 * Jobs should catch this to release back to the queue with appropriate delay.
 *
 * @see PermanentApiFailure For non-retryable failures
 */
abstract class TransientApiFailure extends AbstractApiException
{
    public function __construct(
        string $serviceName,
        public readonly ?int $retryAfter = null,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $serviceName,
            $message !== '' ? $message : "External service '{$serviceName}' is unavailable",
            $previous,
        );
    }
}
