<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateMalformedStringException;
use Illuminate\Support\Facades\Log;

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

    /**
     * Verify API connectivity and authentication.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When session response contains malformed data
     */
    public function verifyConnectivity(): void
    {
        try {
            // Session authentication validates credentials and obtains server URL
            $this->sessionManager->getSession();
        } catch (DateMalformedStringException $e) {
            Log::critical('Linnworks session contains malformed date', [
                'error' => $e->getMessage(),
            ]);

            throw new InvalidApiResponseException(
                'Linnworks',
                'Session response contains malformed date: ' . $e->getMessage(),
                $e,
            );
        }
    }
}
