<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Override;
use Throwable;

/**
 * Thrown when authentication credentials are invalid or expired.
 *
 * Use cases:
 * - API key revoked or expired (401)
 * - Insufficient permissions (403)
 * - OAuth token expired
 *
 * This is typically a configuration/credential issue, not a transient failure.
 * Should NOT retry - requires human intervention to fix credentials.
 *
 * @see ExternalServiceUnavailableException For transient failures (rate limits, outages)
 * @see InvalidApiRequestException For malformed requests
 */
final class AuthenticationExpiredException extends PermanentApiFailure
{
    public readonly string $detail;

    public function __construct(
        string $serviceName,
        string $message = 'Authentication failed',
        ?Throwable $previous = null,
    ) {
        $this->detail = $message;
        parent::__construct($serviceName, 'Authentication failed', $previous);
    }

    #[Override]
    public function context(): array
    {
        return [...parent::context(), 'detail' => $this->detail];
    }
}
