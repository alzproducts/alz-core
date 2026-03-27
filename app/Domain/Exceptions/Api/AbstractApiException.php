<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use App\Domain\Exceptions\DomainException;
use Throwable;

/**
 * Base class for all API-related exceptions.
 *
 * Provides the `serviceName` property shared by all API exceptions.
 * Concrete exceptions extend one of the two category base classes:
 *
 * - {@see PermanentApiFailure} — Non-retryable (auth, validation, not-found)
 * - {@see TransientApiFailure} — Retryable with optional backoff (rate limits, outages)
 *
 * Usage:
 *   catch (AbstractApiException $e) { ... }      // All API failures
 *   catch (PermanentApiFailure $e) { ... }        // Non-retryable only
 *   catch (TransientApiFailure $e) { ... }        // Retryable only
 */
abstract class AbstractApiException extends DomainException
{
    public function __construct(
        public readonly string $serviceName,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return ['service_name' => $this->serviceName];
    }
}
