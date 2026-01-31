<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\Contracts;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use Illuminate\Http\Client\Response;

/**
 * HTTP transport contract for Mixpanel API.
 *
 * Defines the HTTP operations used by Mixpanel API clients.
 * Implementations handle authentication, retries, and error translation.
 *
 * @internal Infrastructure-only interface for decorator pattern
 */
interface MixpanelTransportInterface
{
    /**
     * Perform HTTP request to Mixpanel API.
     *
     * @param string $method HTTP method (GET, POST, PUT, etc.)
     * @param string $url Full URL to request
     * @param string|null $body Request body content
     * @param string|null $contentType Content-Type header value
     * @param bool $retry Whether to apply retry logic for transient failures
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function request(
        string $method,
        string $url,
        ?string $body = null,
        ?string $contentType = null,
        bool $retry = true,
    ): Response;
}
