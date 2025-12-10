<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\BingAdsClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\BingAds\Transformers\BingAdsCsvTransformer;

/**
 * Bing Ads (Microsoft Advertising) API client for campaign data.
 *
 * Responsibilities:
 * 1. Provide ad source identification
 * 2. Verify connectivity
 * 3. Fetch campaign metrics via async reporting API
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
     * Uses Bing Ads async Reporting API:
     * 1. Transport submits report request and polls for completion
     * 2. Transport downloads ZIP and extracts CSV
     * 3. Transformer parses CSV into domain value objects
     *
     * @return list<CampaignMetrics>
     */
    public function getCampaignMetricsByDateRange(DateRange $range): array
    {
        $csv = $this->transport->getCampaignPerformanceReportCsv($range);

        if ($csv === null) {
            return [];
        }

        return BingAdsCsvTransformer::toCampaignMetrics($csv);
    }
}
