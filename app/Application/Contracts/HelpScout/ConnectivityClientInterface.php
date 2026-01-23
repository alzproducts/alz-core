<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;

/**
 * HelpScout connectivity verification contract.
 *
 * Used by verify:api command to validate OAuth2 credentials.
 */
interface ConnectivityClientInterface
{
    /**
     * Verify connectivity to HelpScout API.
     *
     * Makes a lightweight authenticated request to confirm OAuth2 credentials
     * are valid and the API is accessible.
     *
     * @throws AuthenticationExpiredException When OAuth2 credentials are invalid/expired
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     */
    public function verifyConnectivity(): void;
}
