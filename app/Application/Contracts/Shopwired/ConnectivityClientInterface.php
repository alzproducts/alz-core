<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Exceptions\ExternalServiceUnavailableException;

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
     * @throws ExternalServiceUnavailableException When API unavailable or auth fails
     */
    public function verifyConnectivity(): void;
}
