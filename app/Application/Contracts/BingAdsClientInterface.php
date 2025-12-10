<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Bing Ads (Microsoft Advertising)-specific client interface.
 *
 * Extends the generic AdSpendClientInterface with connectivity verification.
 * Used for Bing Ads-specific resolution (e.g., VerifyApiConnectivityCommand).
 */
interface BingAdsClientInterface extends AdSpendClientInterface
{
    /**
     * Verify connectivity and authentication with Bing Ads API.
     *
     * Retrieves account details to validate OAuth credentials
     * and API access.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function verifyConnectivity(): void;
}
