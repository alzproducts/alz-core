<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Google Ads-specific client interface.
 *
 * Extends the generic AdSpendClientInterface with Google-specific operations
 * (connectivity verification, campaign listing) not available on all ad platforms.
 */
interface GoogleAdsClientInterface extends AdSpendClientInterface
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
     * Fetch all active campaigns.
     *
     * @return array<int, Campaign>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function getCampaigns(): array;
}
