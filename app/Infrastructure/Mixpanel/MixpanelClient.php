<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\PayloadSerializationException;
use App\Infrastructure\Mixpanel\DTOs\MixpanelAdSpendEventDTO;
use App\Infrastructure\Support\CsvFormatter;
use Illuminate\Support\Facades\Log;
use JsonException;
use Webmozart\Assert\Assert;

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
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
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
     * @param AdSource $source The ad network these campaigns originate from
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws PayloadSerializationException When payload cannot be encoded
     */
    public function importCampaigns(array $campaigns, AdSource $source): void
    {
        if (\count($campaigns) === 0) {
            return;
        }

        $payload = \array_map(
            static fn(CampaignMetrics $campaign): array => MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign, $source)->toMixpanelFormat(),
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
     * Replace a lookup table with new data.
     *
     * Sends CSV data to Mixpanel Lookup Tables API. The table is identified
     * by $tableKey, which must exist in MixpanelConfig::$lookupTableIds.
     *
     * Note: Mixpanel only supports full replacement (PUT), not incremental updates.
     * Rate limit: 100 calls per 24 hours (hourly syncs recommended).
     *
     * @param string $tableKey Key in MixpanelConfig::$lookupTableIds (e.g., 'utm_campaigns')
     * @param array<int, string> $headers CSV column headers
     * @param array<int, array<int, string>> $rows Pre-transformed data rows
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     */
    public function replaceLookupTable(string $tableKey, array $headers, array $rows): void
    {
        Assert::keyExists(
            $this->config->lookupTableIds,
            $tableKey,
            "Unknown lookup table key: {$tableKey}. Available keys: " . \implode(', ', \array_keys($this->config->lookupTableIds)),
        );

        $csv = CsvFormatter::format($headers, $rows);

        $this->transport->request(
            method: 'PUT',
            url: "{$this->config->dataApiBaseUrl}/lookup-tables/{$this->config->lookupTableIds[$tableKey]}?project_id={$this->config->projectId}",
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
