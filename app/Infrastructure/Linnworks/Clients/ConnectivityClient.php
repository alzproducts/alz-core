<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Infrastructure\Linnworks\LinnworksSessionManager;

/**
 * Linnworks connectivity verification client.
 *
 * Validates API credentials by attempting session authentication.
 * Used for health checks and diagnostics, not business operations.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class ConnectivityClient implements ConnectivityClientInterface
{
    public function __construct(
        private LinnworksSessionManager $sessionManager,
    ) {}

    public function verifyConnectivity(): void
    {
        // Session authentication validates credentials and obtains server URL
        $this->sessionManager->getSession();
    }
}
