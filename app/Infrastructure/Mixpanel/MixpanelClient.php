<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\PayloadSerializationException;
use App\Infrastructure\Support\CsvFormatter;
use Closure;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Manages Mixpanel API interactions for events and lookup tables.
 *
 * Responsibilities:
 * 1. Transform Domain objects to API format
 * 2. Delegate HTTP operations to transport layer
 * 3. Construct API endpoints using configuration
 *
 * HTTP concerns (auth, retry, exception translation) are handled by MixpanelHttpTransport.
 */
final readonly class MixpanelClient implements MixpanelClientInterface
{
    public function __construct(
        private MixpanelHttpTransport $transport,
        private MixpanelConfig $config,
    ) {}

    /**
     * Verify connectivity and authentication with Mixpanel API.
     *
     * Calls the /api/app/me endpoint to validate service account credentials.
     * Uses fail-fast approach (no retry) to detect credential issues immediately.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or credentials invalid
     */
    public function verifyConnectivity(): void
    {
        $this->transport->request(
            method: 'GET',
            url: MixpanelConfig::MAIN_API_URL . '/api/app/me',
            retry: false,
        );
    }

    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics and internally transforms
     * them to Infrastructure DTO for Mixpanel API formatting.
     *
     * @param array<int, CampaignMetrics> $campaigns Domain campaign metrics
     *
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     */
    public function importCampaigns(array $campaigns): void
    {
        if (\count($campaigns) === 0) {
            return;
        }

        $payload = \array_map(
            static fn(CampaignMetrics $campaign): array => MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign)->toMixpanelFormat(),
            $campaigns,
        );

        $this->transport->request(
            method: 'POST',
            url: "{$this->config->dataApiBaseUrl}/import?project_id={$this->config->projectId}",
            body: $this->encodeJson($payload),
            contentType: 'application/json',
        );
    }

    /**
     * Replace the campaign lookup table with latest campaign data.
     *
     * @param array<int, Campaign> $campaigns
     *
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     */
    public function replaceCampaignLookupTable(array $campaigns): void
    {
        $this->replaceLookupTable(
            tableName: 'utm_campaigns',
            headers: ['utm_campaign', 'campaign_name', 'campaign_status'],
            rowMapper: static fn(Campaign $campaign): array => [
                (string) $campaign->id,
                $campaign->name,
                $campaign->status,
            ],
            items: $campaigns,
        );
    }

    /**
     * Replace a lookup table with new data.
     *
     * @template T
     *
     * @param string $tableName Key in config lookup_tables array
     * @param array<int, string> $headers CSV column headers
     * @param Closure(T): array<int, string> $rowMapper Maps each item to a CSV row
     * @param array<int, T> $items Items to transform into rows
     *
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     */
    private function replaceLookupTable(string $tableName, array $headers, Closure $rowMapper, array $items): void
    {
        $rows = \array_map($rowMapper, $items);
        $csv = CsvFormatter::format($headers, $rows);

        $this->transport->request(
            method: 'PUT',
            url: "{$this->config->dataApiBaseUrl}/lookup-tables/{$this->config->lookupTableIds[$tableName]}?project_id={$this->config->projectId}",
            body: $csv,
            contentType: 'text/csv',
        );
    }

    /**
     * Encode payload as JSON with exception translation.
     *
     * @param array<int, array<string, mixed>> $payload
     *
     * @throws PayloadSerializationException When payload cannot be encoded (data integrity issue)
     */
    private function encodeJson(array $payload): string
    {
        try {
            return \json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('Mixpanel payload encoding failed', [
                'error' => $e->getMessage(),
            ]);

            throw new PayloadSerializationException('Mixpanel', $e->getMessage(), $e);
        }
    }
}
