<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Clients;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Linnworks\Clients\ConnectivityClient;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ConnectivityClient Unit Tests.
 *
 * Tests the Linnworks connectivity verification client:
 * - Validates credentials by attempting session authentication
 * - Propagates authentication exceptions to caller
 * - Propagates service unavailable exceptions to caller
 */
#[CoversClass(ConnectivityClient::class)]
final class ConnectivityClientTest extends TestCase
{
    private MockInterface&LinnworksSessionManager $sessionManager;
    private ConnectivityClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionManager = Mockery::mock(LinnworksSessionManager::class);
        $this->client = new ConnectivityClient($this->sessionManager);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Connectivity Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_connectivity_by_obtaining_session(): void
    {
        $session = new LinnworksSession(
            token: 'valid-token',
            serverUrl: 'https://eu-ext.linnworks.net',
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andReturn($session);

        // Should complete without exception
        $this->client->verifyConnectivity();

        // Verify the session manager was called
        $this->sessionManager->shouldHaveReceived('getSession')->once();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Failure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_propagates_authentication_exception(): void
    {
        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow(new AuthenticationExpiredException(
                'Linnworks',
                'Invalid credentials',
            ));

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->client->verifyConnectivity();
    }

    /*
    |--------------------------------------------------------------------------
    | Service Unavailable Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_propagates_service_unavailable_exception(): void
    {
        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_preserves_retry_after_from_service_unavailable_exception(): void
    {
        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks', 120));

        try {
            $this->client->verifyConnectivity();
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertSame(120, $e->retryAfter);
        }
    }
}
