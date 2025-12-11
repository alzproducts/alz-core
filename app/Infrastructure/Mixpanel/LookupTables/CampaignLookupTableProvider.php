<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\LookupTables;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\LookupTableProviderInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;

/**
 * Provides campaign lookup table data from Google Ads.
 *
 * Fetches campaign metadata (ID, name, status) and transforms it
 * to the tabular format expected by Mixpanel Lookup Tables.
 */
final readonly class CampaignLookupTableProvider implements LookupTableProviderInterface
{
    public function __construct(
        private GoogleAdsClientInterface $googleAdsClient,
    ) {}

    public function getTableKey(): string
    {
        return 'utm_campaigns';
    }

    public function getSourceName(): string
    {
        return 'Google Ads';
    }

    /**
     * @return list<string>
     */
    public function getHeaders(): array
    {
        return ['utm_campaign', 'campaign_name', 'campaign_status'];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function fetchRows(): array
    {
        $campaigns = $this->googleAdsClient->getCampaigns();

        return \array_map(
            static fn(Campaign $campaign): array => [
                (string) $campaign->id,
                $campaign->name,
                $campaign->status,
            ],
            $campaigns,
        );
    }
}
