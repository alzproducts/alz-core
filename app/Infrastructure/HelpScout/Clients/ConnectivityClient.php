<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;

/**
 * HelpScout connectivity verification client.
 *
 * Uses the shared transport to make a lightweight authenticated request
 * that validates OAuth2 credentials without heavy data processing.
 *
 * @template-pattern API Connectivity Client
 */
final readonly class ConnectivityClient implements ConnectivityClientInterface
{
    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     */
    public function verifyConnectivity(): void
    {
        // GET /mailboxes is lightweight and validates OAuth2 auth
        $this->transport->get('/mailboxes');
    }
}
