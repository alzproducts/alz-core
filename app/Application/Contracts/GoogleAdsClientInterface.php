<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

interface GoogleAdsClientInterface
{
    /**
     * Verify connectivity and authentication with Google Ads API.
     *
     * Executes a minimal GAQL query to validate OAuth credentials
     * and API access without fetching significant data.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function verifyConnectivity(): void;

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
     * Fetch all active campaigns.
     *
     * @return array<int, Campaign>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function getCampaigns(): array;
}
