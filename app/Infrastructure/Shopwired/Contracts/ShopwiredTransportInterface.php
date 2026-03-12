<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Contracts;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\RetryStrategy;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * HTTP transport contract for ShopWired API.
 *
 * Defines the HTTP operations used by ShopWired API clients.
 * Implementations handle authentication, retries, and error translation.
 *
 * @internal Infrastructure-only interface for decorator pattern
 */
interface ShopwiredTransportInterface
{
    /**
     * Perform GET request to ShopWired API.
     *
     * @param string $endpoint API endpoint path (e.g., 'business')
     * @param array<string, mixed> $query Optional query parameters
     * @param bool $retry Whether to apply retry logic for transient failures
     * @param RetryStrategy $strategy Retry configuration (only used when $retry is true)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function get(
        string $endpoint,
        array $query = [],
        bool $retry = true,
        RetryStrategy $strategy = RetryStrategy::Background,
    ): Response;

    /**
     * Perform POST request to ShopWired API.
     *
     * @param string $endpoint API endpoint path (e.g., 'orders/123/status')
     * @param array<mixed> $data Request body data (sent as JSON)
     * @param bool $retry Whether to apply retry logic for transient failures
     * @param RetryStrategy $strategy Retry configuration (only used when $retry is true)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function post(
        string $endpoint,
        array $data = [],
        bool $retry = true,
        RetryStrategy $strategy = RetryStrategy::Background,
    ): Response;

    /**
     * Perform PUT request to ShopWired API.
     *
     * @param string $endpoint API endpoint path (e.g., 'products/123')
     * @param array<string, mixed> $data Request body data (sent as JSON)
     * @param bool $retry Whether to apply retry logic for transient failures
     * @param RetryStrategy $strategy Retry configuration (only used when $retry is true)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function put(
        string $endpoint,
        array $data = [],
        bool $retry = true,
        RetryStrategy $strategy = RetryStrategy::Background,
    ): Response;

    /**
     * Fetch a single resource by ID with proper 404 context.
     *
     * Use this for single-resource fetches (getOrderById, getCustomerById, etc.)
     * where 404 should throw ResourceNotFoundException with meaningful context.
     *
     * @param string $resourceType Resource type for exception context (e.g., 'Order', 'Customer')
     * @param int|string $id Resource ID
     * @param string $endpoint API endpoint path (e.g., 'orders')
     * @param array<string, mixed> $query Optional query parameters
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404) - with proper context
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function getResource(
        string $resourceType,
        int|string $id,
        string $endpoint,
        array $query = [],
    ): Response;

    /**
     * Perform concurrent POST requests to ShopWired API.
     *
     * Uses Http::pool() for parallel execution of multiple POST requests.
     * Each request is configured with auth and retry logic. Returns keyed
     * Response array - caller handles validation of responses.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests Keyed array of endpoint/data pairs
     *
     * @return array<string, Response> Keyed responses matching input keys
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function poolPost(array $requests): array;
}
