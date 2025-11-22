<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\Mixpanel;

use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\AdSpendEvent;
use App\Infrastructure\Support\ApiRetryStrategy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends ad spend events to Mixpanel for analytics tracking.
 *
 * Responsibilities:
 * 1. Convert domain events to Mixpanel API format
 * 2. Batch POST events to Mixpanel API endpoint with automatic retries
 * 3. Handle API errors and rate limiting
 *
 * Design: Uses Laravel's Http facade with built-in retry mechanism via ApiRetryStrategy.
 * Retry Strategy:
 * - Retries up to 3 times on transient failures (5xx and 429)
 * - Uses exponential backoff with 100ms base delay
 * - Uses ApiRetryStrategy::defaultRetry() to determine retry eligibility
 *
 * Error Handling:
 * - 429 after all retries → ApiRateLimitException
 * - Other 4xx/5xx errors → MixpanelApiException
 */
final readonly class MixpanelClient implements MixpanelClientInterface
{
    public function __construct(
        private string $mixpanelToken,
        private string $mixpanelBaseUrl,
    ) {}

    /**
     * @param array<int, AdSpendEvent> $events
     *
     * @throws MixpanelApiException
     * @throws ApiRateLimitException|ConnectionException
     */
    public function importBatch(array $events): void
    {
        if (\count($events) === 0) {
            return;
        }

        // Convert events to Mixpanel format
        $payload = [];
        foreach ($events as $event) {
            $payload[] = $event->toMixpanelFormat();
        }

        try {
            Http::asJson()
                ->retry(
                    times: 3,
                    sleepMilliseconds: 100,
                    when: ApiRetryStrategy::defaultRetry(),
                )
                ->post("{$this->mixpanelBaseUrl}/import", [
                    'token' => $this->mixpanelToken,
                    'data' => $payload,
                ])
                ->throw();
        } catch (RequestException $e) {
            // Handle rate limiting (429)
            if ($e->response->status() === 429) {
                throw new ApiRateLimitException(
                    'Mixpanel API rate limit exceeded after retries',
                    $this->extractRetryAfter($e->response),
                    $e,
                );
            }

            // All other errors (4xx/5xx) become MixpanelApiException
            throw new MixpanelApiException(
                $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Extract retry-after seconds from response headers.
     *
     * Mixpanel includes Retry-After header when rate limited.
     */
    private function extractRetryAfter(Response $response): int
    {
        $retryAfter = 60; // Default to 60 seconds

        $retryAfterHeader = $response->header('Retry-After');
        if (\is_numeric($retryAfterHeader)) {
            $extracted = (int) $retryAfterHeader;
            if ($extracted > 0) {
                $retryAfter = $extracted;
            }
        }

        return $retryAfter;
    }
}
