<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Mixpanel\Contracts\MixpanelTransportInterface;
use App\Infrastructure\Mixpanel\Enums\MixpanelLogLevel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Logging decorator for Mixpanel HTTP transport.
 *
 * Wraps the base transport to add configurable request/response logging.
 * Useful for debugging API issues without modifying production code.
 *
 * Security: Auth credentials are never logged - they're added in the base
 * transport's createBaseRequest() method after this decorator runs.
 *
 * @template-pattern Decorator
 */
final readonly class LoggingMixpanelTransport implements MixpanelTransportInterface
{
    private const string SERVICE_NAME = 'Mixpanel';
    private const int MAX_BODY_LENGTH = 1000;

    public function __construct(
        private MixpanelTransportInterface $inner,
        private MixpanelLogLevel $logLevel,
    ) {}

    /**
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
    ): Response {
        $endpoint = self::extractEndpoint($url);

        $this->logRequest($method, $endpoint, $body);
        $start = \microtime(true);

        $response = $this->inner->request($method, $url, $body, $contentType, $retry);

        $this->logResponse($endpoint, $response, \microtime(true) - $start);

        return $response;
    }

    /**
     * Extract endpoint path from full URL for logging.
     *
     * Converts full URLs to concise endpoint paths:
     * - https://api-eu.mixpanel.com/import?project_id=123 → /import?project_id=123
     * - https://data-eu.mixpanel.com/api/2.0/export?... → /api/2.0/export?...
     */
    private static function extractEndpoint(string $url): string
    {
        $path = \parse_url($url, PHP_URL_PATH);
        $query = \parse_url($url, PHP_URL_QUERY);

        // Handle malformed URLs or missing path gracefully
        if (!\is_string($path)) {
            return '/';
        }

        return \is_string($query) ? "{$path}?{$query}" : $path;
    }

    /**
     * Log outgoing request details.
     */
    private function logRequest(string $method, string $endpoint, ?string $body): void
    {
        $context = ['method' => $method, 'endpoint' => $endpoint];

        if ($this->logLevel === MixpanelLogLevel::Debug && $body !== null) {
            $context['body'] = self::truncate($body);
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

        if ($this->logLevel === MixpanelLogLevel::Debug) {
            $context['body'] = self::truncate($response->body());
        }

        Log::debug(self::SERVICE_NAME . ' API response', $context);
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
}
