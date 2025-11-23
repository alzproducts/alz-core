<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\CsvFormatter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Manages Mixpanel API interactions for events and lookup tables.
 *
 * Responsibilities:
 * 1. Send ad spend events to Mixpanel Import API with HTTP Basic Auth
 * 2. Replace campaign lookup table via Lookup Tables API with HTTP Basic Auth
 * 3. Format data and handle API errors
 *
 * Authentication: HTTP Basic Auth with Service Account credentials
 * - Applies to both Import Events and Lookup Tables APIs
 * - Username: service_account_username
 * - Password: service_account_password
 *
 * Error Handling:
 * - 429 after all retries → ApiRateLimitException
 * - Other 4xx/5xx errors → MixpanelApiException
 */
final readonly class MixpanelClient implements MixpanelClientInterface
{
    public function __construct(
        private string $mixpanelBaseUrl,
        private string $serviceAccountUsername,
        private string $serviceAccountPassword,
        private string $projectId,
        private string $lookupTableId,
    ) {}

    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics and internally transforms
     * them to Infrastructure DTO for Mixpanel API formatting.
     *
     * @param array<int, CampaignMetrics> $campaigns Domain campaign metrics
     *
     * @throws MixpanelApiException
     * @throws ApiRateLimitException|ConnectionException
     */
    public function importCampaigns(array $campaigns): void
    {
        if (\count($campaigns) === 0) {
            return;
        }

        // Transform Domain objects to Infrastructure DTOs
        $events = \array_map(
            static fn(CampaignMetrics $campaign): MixpanelAdSpendEventDTO => MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign),
            $campaigns,
        );

        // Convert events to Mixpanel format
        $payload = \array_map(
            static fn(MixpanelAdSpendEventDTO $event) => $event->toMixpanelFormat(),
            $events,
        );

        try {
            Http::asJson()
                ->withBasicAuth($this->serviceAccountUsername, $this->serviceAccountPassword)
                ->retry(
                    times: 3,
                    sleepMilliseconds: 100,
                    when: ApiRetryStrategy::defaultRetry(),
                )
                ->post("{$this->mixpanelBaseUrl}/import?project_id={$this->projectId}", $payload)
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
     * Replace the campaign lookup table with latest campaign data.
     *
     * @param array<int, Campaign> $campaigns
     *
     * @throws MixpanelApiException
     * @throws ApiRateLimitException|ConnectionException
     */
    public function replaceCampaignLookupTable(array $campaigns): void
    {
        // Format campaigns as RFC 4180-compliant CSV
        $headers = ['utm_campaign', 'campaign_name', 'campaign_status'];
        $rows = \array_map(
            static fn(Campaign $campaign) => [
                (string) $campaign->campaignId,
                $campaign->campaignName,
                $campaign->status,
            ],
            $campaigns,
        );
        $csv = CsvFormatter::format($headers, $rows);

        try {
            Http::withBasicAuth($this->serviceAccountUsername, $this->serviceAccountPassword)
                ->withBody($csv, 'text/csv')
                ->timeout(60)
                ->retry(
                    times: 3,
                    sleepMilliseconds: 100,
                    when: ApiRetryStrategy::defaultRetry(),
                )
                ->put("{$this->mixpanelBaseUrl}/lookup_tables/{$this->projectId}/{$this->lookupTableId}")
                ->throw();
        } catch (RequestException $e) {
            // Handle rate limiting (429)
            if ($e->response->status() === 429) {
                throw new ApiRateLimitException(
                    'Mixpanel Lookup Table API rate limit exceeded',
                    $this->extractRetryAfter($e->response),
                    $e,
                );
            }

            throw new MixpanelApiException(
                "Mixpanel Lookup Table API error ({$e->response->status()}): {$e->response->body()}",
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
