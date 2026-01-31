<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Enums\LinnworksLogLevel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Logging decorator for Linnworks HTTP transport.
 *
 * Wraps the base transport to add configurable request/response logging.
 * Useful for debugging API issues without modifying production code.
 *
 * Security: Auth headers are never logged - they're added in the base
 * transport's createBaseRequest() method after this decorator runs.
 *
 * @template-pattern Decorator
 */
final readonly class LoggingLinnworksTransport implements LinnworksTransportInterface
{
    private const string SERVICE_NAME = 'Linnworks';
    private const int MAX_BODY_LENGTH = 1000;

    public function __construct(
        private LinnworksTransportInterface $inner,
        private LinnworksLogLevel $logLevel,
    ) {}

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function get(string $endpoint, array $query = []): Response
    {
        $this->logRequest('GET', $endpoint, $query);
        $start = \microtime(true);

        $response = $this->inner->get($endpoint, $query);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400) or data not serializable
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function post(string $endpoint, array $data = []): Response
    {
        $this->logRequest('POST', $endpoint, $data);
        $start = \microtime(true);

        $response = $this->inner->post($endpoint, $data);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function postJson(string $endpoint, array $data = []): Response
    {
        $this->logRequest('POST_JSON', $endpoint, $data);
        $start = \microtime(true);

        $response = $this->inner->postJson($endpoint, $data);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function postFormParams(string $endpoint, array $params = []): Response
    {
        $this->logRequest('POST_FORM', $endpoint, $params);
        $start = \microtime(true);

        $response = $this->inner->postFormParams($endpoint, $params);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
     * Log outgoing request details.
     *
     * @param array<string, mixed> $data
     */
    private function logRequest(string $method, string $endpoint, array $data): void
    {
        $context = ['method' => $method, 'endpoint' => $endpoint];

        if ($this->logLevel === LinnworksLogLevel::Debug && $data !== []) {
            $context['body'] = $this->truncate(self::safeJsonEncode($data));
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

        if ($this->logLevel === LinnworksLogLevel::Debug) {
            $context['body'] = $this->truncate($response->body());
        }

        Log::debug(self::SERVICE_NAME . ' API response', $context);
    }

    /**
     * Truncate body to prevent log bloat.
     */
    private function truncate(string $body): string
    {
        return \mb_strlen($body) <= self::MAX_BODY_LENGTH
            ? $body
            : \mb_substr($body, 0, self::MAX_BODY_LENGTH) . '...(truncated)';
    }

    /**
     * Safely encode data to JSON, returning fallback on failure.
     *
     * @param array<string, mixed> $data
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
