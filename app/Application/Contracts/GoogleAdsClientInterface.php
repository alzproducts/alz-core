<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Google Ads-specific client interface.
 *
 * Extends the generic AdSpendClientInterface with connectivity verification.
 * Used for Google Ads-specific resolution (e.g., VerifyApiConnectivityCommand).
 */
interface GoogleAdsClientInterface extends AdSpendClientInterface
{
    /**
     * Verify connectivity and authentication with Google Ads API.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
     */
    public function verifyConnectivity(): void;
}
