<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Contract for Linnworks API connectivity verification.
 *
 * This interface defines the boundary between Application and Infrastructure
 * for Linnworks connectivity checks. Used for health checks and diagnostics,
 * not business operations.
 *
 * @template-pattern API Connectivity Interface
 */
interface ConnectivityClientInterface
{
    /**
     * Verify API connectivity and authentication.
     *
     * Performs session authentication to validate credentials without
     * business logic side effects. Used for health checks and diagnostics.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When session response contains malformed data
     */
    public function verifyConnectivity(): void;
}
