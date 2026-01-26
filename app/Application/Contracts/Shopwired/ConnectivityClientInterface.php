<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;

/**
 * Contract for Shopwired API connectivity verification.
 *
 * This interface defines the boundary between Application and Infrastructure
 * for Shopwired connectivity checks. Used for health checks and diagnostics,
 * not business operations.
 *
 * @template-pattern API Connectivity Interface
 */
interface ConnectivityClientInterface
{
    /**
     * Verify API connectivity and authentication.
     *
     * Performs a lightweight request to validate credentials without
     * business logic side effects. Used for health checks and diagnostics.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    public function verifyConnectivity(): void;
}
