<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\Mixpanel;

use App\Domain\AdSpend\Contracts\MixpanelCampaignLookupClientInterface;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Manages Mixpanel Lookup Tables for campaign UTM mapping.
 *
 * Responsibilities:
 * 1. Format campaigns as RFC 4180-compliant CSV
 * 2. Upload via HTTP PUT to Mixpanel's Lookup Tables API
 * 3. Handle API errors and rate limiting
 *
 * Design Notes:
 * - Mixpanel only supports full replacement (PUT), not incremental updates
 * - CSV structure: utm_campaign,campaign_name,campaign_status (utm_campaign is first column)
 * - Rate limit: 100 calls per 24 hours (hourly syncs recommended)
 * - Authentication: Bearer token (Service Account, not project token)
 */
final readonly class MixpanelLookupTableClient implements MixpanelCampaignLookupClientInterface
{
    public function __construct(
        private string $mixpanelServiceUrl,
        private string $mixpanelServiceToken,
        private string $mixpanelWorkspaceId,
        private string $lookupTableName,
    ) {}

    /**
     * Replace the campaign lookup table with latest campaign data.
     *
     * @param array<int, Campaign> $campaigns
     * @throws MixpanelApiException
     */
    public function replaceCampaignLookupTable(array $campaigns): void
    {
        // Format campaigns as RFC 4180-compliant CSV
        $csv = $this->formatCsv($campaigns);

        try {
            // Upload to Mixpanel Lookup Tables API with raw CSV body
            Http::withToken($this->mixpanelServiceToken)
                ->withBody($csv, 'text/csv')
                ->timeout(60)
                ->retry(times: 1, sleepMilliseconds: 1000)
                ->put("{$this->mixpanelServiceUrl}/lookuptables/{$this->mixpanelWorkspaceId}/{$this->lookupTableName}")
                ->throw();
        } catch (RequestException $e) {
            throw new MixpanelApiException(
                "Mixpanel Lookup Table API error ({$e->response->status()}): {$e->response->body()}",
                0,
                $e,
            );
        }
    }

    /**
     * Format campaigns as RFC 4180-compliant CSV.
     *
     * CSV structure: utm_campaign,campaign_name,campaign_status
     * - utm_campaign (first column, based on campaign ID)
     * - campaign_name (human-readable name)
     * - campaign_status (ENABLED, PAUSED, REMOVED, UNSPECIFIED)
     *
     * @param array<int, Campaign> $campaigns
     */
    private function formatCsv(array $campaigns): string
    {
        $lines = [];

        // Add CSV header
        $lines[] = $this->escapeCsvValue('utm_campaign') . ','
            . $this->escapeCsvValue('campaign_name') . ','
            . $this->escapeCsvValue('campaign_status');

        // Add campaign rows
        foreach ($campaigns as $campaign) {
            // utm_campaign: campaign ID (used to match against UTM parameters)
            $utmCampaign = $this->escapeCsvValue((string) $campaign->campaignId);

            // campaign_name: human-readable name
            $campaignName = $this->escapeCsvValue($campaign->campaignName);

            // campaign_status: campaign status
            $status = $this->escapeCsvValue($campaign->status);

            $lines[] = "$utmCampaign,$campaignName,$status";
        }

        // RFC 4180: Rows separated by CRLF
        return \implode("\r\n", $lines) . "\r\n";
    }

    /**
     * RFC 4180 CSV value escaping.
     *
     * Rules:
     * - Fields with commas, double quotes, or newlines must be quoted
     * - Double quotes within quoted fields are escaped by doubling them
     * - Whitespace is preserved (no trimming)
     */
    private function escapeCsvValue(string $value): string
    {
        // Check if value needs quoting
        if (\str_contains($value, ',') || \str_contains($value, '"') || \str_contains($value, "\n")) {
            // Escape double quotes by doubling them
            $value = \str_replace('"', '""', $value);

            // Wrap in quotes
            return "\"$value\"";
        }

        return $value;
    }
}
