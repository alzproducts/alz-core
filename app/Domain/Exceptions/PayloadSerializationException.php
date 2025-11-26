<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

/**
 * Thrown when Domain data cannot be serialized for external transmission.
 *
 * Use cases:
 * - JSON encoding fails for API request payload
 * - Data contains values that cannot be serialized (resources, closures)
 * - Encoding produces invalid output
 *
 * This is a data integrity issue - the Domain objects are in an
 * unexpected state that prevents serialization. Should be logged
 * at ERROR level with payload details for debugging.
 *
 * @see InvalidApiResponseException For malformed API responses (inbound)
 * @see ExternalServiceUnavailableException For network/API failures
 */
final class PayloadSerializationException extends DomainException
{
    public function __construct(
        public readonly string $serviceName,
        string $message = 'Failed to serialize payload',
        ?Throwable $previous = null,
    ) {
        parent::__construct("{$serviceName}: {$message}", 0, $previous);
    }
}
