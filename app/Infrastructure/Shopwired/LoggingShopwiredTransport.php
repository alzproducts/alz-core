<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Enums\ShopwiredLogLevel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * Logging decorator for ShopWired HTTP transport.
 *
 * Wraps the base transport to add configurable request/response logging.
 * Useful for debugging API issues without modifying production code.
 *
 * Security: Auth credentials are never logged - they're added in the base
 * transport's createBaseRequest() method after this decorator runs.
 *
 * @template-pattern Decorator
 */
final readonly class LoggingShopwiredTransport implements ShopwiredTransportInterface
{
    private const string SERVICE_NAME = 'Shopwired';
    private const int MAX_BODY_LENGTH = 1000;

    public function __construct(
        private ShopwiredTransportInterface $inner,
        private ShopwiredLogLevel $logLevel,
    ) {}

    /**
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
    ): Response {
        $this->logRequest('GET', $endpoint, $query);
        $start = \microtime(true);

        $response = $this->inner->get($endpoint, $query, $retry, $strategy);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
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
    ): Response {
        $this->logRequest('POST', $endpoint, $data);
        $start = \microtime(true);

        $response = $this->inner->post($endpoint, $data, $retry, $strategy);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
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
    ): Response {
        $this->logRequest('PUT', $endpoint, $data);
        $start = \microtime(true);

        $response = $this->inner->put($endpoint, $data, $retry, $strategy);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
     * Delegate directly to inner transport - the underlying get() will be logged.
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
    ): Response {
        // Delegate directly - the underlying get() call will be logged.
        // The endpoint like "orders/12345" already conveys the resource context.
        return $this->inner->getResource($resourceType, $id, $endpoint, $query);
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function poolPost(array $requests): array
    {
        self::logPoolRequest($requests);
        $start = \microtime(true);

        $responses = $this->inner->poolPost($requests);

        self::logPoolResponse($responses, \microtime(true) - $start);

        return $responses;
    }

    /**
     * Log outgoing request details.
     *
     * @param array<mixed> $data
     */
    private function logRequest(string $method, string $endpoint, array $data): void
    {
        $context = ['method' => $method, 'endpoint' => $endpoint];

        if ($this->logLevel === ShopwiredLogLevel::Debug && $data !== []) {
            $context['body'] = self::truncate(self::safeJsonEncode($data));
        }

        Log::debug(self::SERVICE_NAME . ' API request', $context);
    }

    /**
     * Log incoming response details.
     */
    private function logResponse(string $endpoint, Response $response, float $duration): void
    {
        $context = [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'duration_ms' => \round($duration * 1000, 2),
        ];

        if ($this->logLevel === ShopwiredLogLevel::Debug) {
            $context['body'] = self::truncate($response->body());
        }

        Log::debug(self::SERVICE_NAME . ' API response', $context);
    }

    /**
     * Log outgoing pool request summary.
     *
     * @param array<string, array{endpoint: string, data: array<mixed>}> $requests
     */
    private static function logPoolRequest(array $requests): void
    {
        $endpoints = \array_map(
            static fn(array $r): string => $r['endpoint'],
            $requests,
        );

        $context = [
            'method' => 'POOL_POST',
            'request_count' => \count($requests),
            'endpoints' => $endpoints,
        ];

        Log::debug(self::SERVICE_NAME . ' API pool request', $context);
    }

    /**
     * Log pool response summary.
     *
     * @param array<string, Response> $responses
     */
    private static function logPoolResponse(array $responses, float $duration): void
    {
        $context = [
            'response_count' => \count($responses),
            'duration_ms' => \round($duration * 1000, 2),
        ];

        Log::debug(self::SERVICE_NAME . ' API pool response', $context);
    }

    /**
     * Truncate body to prevent log bloat.
     */
    private static function truncate(string $body): string
    {
        return \mb_strlen($body) <= self::MAX_BODY_LENGTH
            ? $body
            : \mb_substr($body, 0, self::MAX_BODY_LENGTH) . '...(truncated)';
    }

    /**
     * Safely encode data to JSON, returning fallback on failure.
     *
     * @param array<mixed> $data
     */
    private static function safeJsonEncode(array $data): string
    {
        try {
            return \json_encode($data, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[unserializable data]';
        }
    }
}
