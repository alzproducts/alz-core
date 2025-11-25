<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Support\ApiRetryStrategy;
use App\Infrastructure\Support\CsvFormatter;
use App\Infrastructure\Support\RetryAfterParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 * - Catches SDK exceptions (RequestException, MixpanelApiException)
 * - Logs technical details with context (using ApiRateLimitException for rate limit detection)
 * - Translates to Domain exception (ExternalServiceUnavailableException)
 */
final readonly class MixpanelClient implements MixpanelClientInterface
{
    /**
     * Mixpanel main API base URL for service account verification.
     * This is different from the data API URL used for imports.
     */
    private const string MIXPANEL_API_URL = 'https://mixpanel.com';

    public function __construct(
        private string $mixpanelBaseUrl,
        private string $serviceAccountUsername,
        private string $serviceAccountPassword,
        private string $projectId,
        private string $lookupTableId,
    ) {}

    /**
     * Verify connectivity and authentication with Mixpanel API.
     *
     * Calls the /api/app/me endpoint to validate service account credentials.
     * This endpoint returns the authenticated user/service account details.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or credentials invalid
     */
    public function verifyConnectivity(): void
    {
        try {
            Http::withBasicAuth($this->serviceAccountUsername, $this->serviceAccountPassword)
                ->timeout(10)
                ->get(self::MIXPANEL_API_URL . '/api/app/me')
                ->throw();
        } catch (RequestException $e) {
            Log::error('Mixpanel connectivity verification failed', [
                'status' => $e->response->status(),
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException('Mixpanel', previous: $e);
        }
    }

    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics and internally transforms
     * them to Infrastructure DTO for Mixpanel API formatting.
     *
     * @param array<int, CampaignMetrics> $campaigns Domain campaign metrics
     *
     * @throws ExternalServiceUnavailableException
     * @throws ConnectionException
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
            // Detect rate limit and extract retryAfter if available
            $retryAfter = null;
            if ($e->response->status() === 429) {
                $retryAfter = RetryAfterParser::parse($e->response->header('Retry-After'));
                Log::warning('Mixpanel rate limited', [
                    'retry_after' => $retryAfter,
                    'error' => $e->getMessage(),
                ]);
            } else {
                Log::error('Mixpanel API error', [
                    'status' => $e->response->status(),
                    'error' => $e->getMessage(),
                ]);
            }

            // Translate to Domain exception with retryAfter if available
            throw new ExternalServiceUnavailableException('Mixpanel', $retryAfter, $e);
        }
    }

    /**
     * Replace the campaign lookup table with latest campaign data.
     *
     * @param array<int, Campaign> $campaigns
     *
     * @throws ExternalServiceUnavailableException
     * @throws ConnectionException
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
            // Detect rate limit and extract retryAfter if available
            $retryAfter = null;
            if ($e->response->status() === 429) {
                $retryAfter = RetryAfterParser::parse($e->response->header('Retry-After'));
                Log::warning('Mixpanel Lookup Table rate limited', [
                    'retry_after' => $retryAfter,
                    'error' => $e->getMessage(),
                ]);
            } else {
                Log::error('Mixpanel Lookup Table API error', [
                    'status' => $e->response->status(),
                    'error' => $e->getMessage(),
                ]);
            }

            // Translate to Domain exception with retryAfter if available
            throw new ExternalServiceUnavailableException('Mixpanel', $retryAfter, $e);
        }
    }
}
