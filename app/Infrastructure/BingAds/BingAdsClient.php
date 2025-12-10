<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\BingAdsClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\ValueObjects\DateRange;
use BadMethodCallException;

/**
 * Bing Ads (Microsoft Advertising) API client for campaign data.
 *
 * Responsibilities:
 * 1. Provide ad source identification
 * 2. Verify connectivity
 * 3. Fetch campaign metrics (Stage 5)
 * 4. Fetch campaigns (Stage 5)
 *
 * Design: Pure business logic - delegates all SDK interaction to BingAdsTransport.
 * Exception handling is done in the transport layer.
 *
 * @template-pattern API Client Business Logic
 */
final readonly class BingAdsClient implements BingAdsClientInterface
{
    public function __construct(
        private BingAdsTransport $transport,
    ) {}

    public function getSource(): AdSource
    {
        return AdSource::Bing;
    }

    /**
     * Verify connectivity and authentication with Bing Ads API.
     *
     * Retrieves account details to validate OAuth credentials
     * and confirm API access is working.
     */
    public function verifyConnectivity(): void
    {
        // Simply calling getAccount validates authentication and connectivity
        $this->transport->getAccount();
    }

    /**
     * Fetch campaign metrics for a date range.
     *
     * @return list<CampaignMetrics>
     *
     * @throws BadMethodCallException Not yet implemented (Stage 5)
     */
    public function getCampaignMetricsByDateRange(DateRange $range): array
    {
        throw new BadMethodCallException(
            'getCampaignMetricsByDateRange not yet implemented. See Stage 5 of implementation plan.',
        );
    }

    /**
     * Fetch all active campaigns.
     *
     * @return list<Campaign>
     *
     * @throws BadMethodCallException Not yet implemented (Stage 5)
     */
    public function getCampaigns(): array
    {
        throw new BadMethodCallException(
            'getCampaigns not yet implemented. See Stage 5 of implementation plan.',
        );
    }
}
