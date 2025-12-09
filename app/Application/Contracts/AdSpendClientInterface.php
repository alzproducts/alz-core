<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Generic interface for ad spend data retrieval.
 *
 * Enables Strategy pattern for multi-source ad spend syncing:
 * Google Ads, Bing Ads, Facebook Ads all implement this interface.
 * The SyncAdSpendUseCase works with any implementation.
 */
interface AdSpendClientInterface
{
    /**
     * Fetch daily campaign metrics for a specific date.
     *
     * @return array<int, CampaignMetrics>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function getDailyCampaignMetrics(string $date): array;

    /**
     * Get the ad source this client retrieves data from.
     *
     * Used by use cases to identify which ad platform
     * the metrics originated from (for analytics tagging).
     */
    public function getSource(): AdSource;
}
