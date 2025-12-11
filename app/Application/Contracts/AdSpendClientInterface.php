<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\DateRange;

/**
 * Generic interface for ad spend data retrieval.
 *
 * Enables Strategy pattern for multi-source ad spend syncing:
 * Google Ads, Bing Ads, Facebook Ads all implement this interface.
 */
interface AdSpendClientInterface
{
    /**
     * Get the ad source this client retrieves data from.
     */
    public function getSource(): AdSource;

    /**
     * Fetch campaign metrics for a date range.
     *
     * @return array<int, CampaignMetrics>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function getCampaignMetricsByDateRange(DateRange $range): array;
}
