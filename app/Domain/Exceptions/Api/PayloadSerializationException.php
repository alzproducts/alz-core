<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Override;
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
final class PayloadSerializationException extends PermanentApiFailure
{
    public readonly string $detail;

    public function __construct(
        string $serviceName,
        string $message = 'Failed to serialize payload',
        ?Throwable $previous = null,
    ) {
        $this->detail = $message;
        parent::__construct($serviceName, 'Failed to serialize payload', $previous);
    }

    #[Override]
    public function context(): array
    {
        return [...parent::context(), 'detail' => $this->detail];
    }
}
