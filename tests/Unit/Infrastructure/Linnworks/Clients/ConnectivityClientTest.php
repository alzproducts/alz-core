<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Clients;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Linnworks\Clients\ConnectivityClient;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
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

    /*
    |--------------------------------------------------------------------------
    | DateMalformedStringException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_date_malformed_exception_to_invalid_api_response(): void
    {
        $dateException = new DateMalformedStringException('Invalid date format: not-a-date');

        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow($dateException);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Session response contains malformed date');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_logs_critical_when_date_is_malformed(): void
    {
        $dateException = new DateMalformedStringException('Invalid date format: garbage');

        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow($dateException);

        Log::shouldReceive('critical')
            ->once()
            ->with(
                'Linnworks session contains malformed date',
                Mockery::on(static fn(array $ctx): bool => isset($ctx['error']) && \str_contains($ctx['error'], 'garbage')),
            );

        try {
            $this->client->verifyConnectivity();
        } catch (InvalidApiResponseException) {
            // Expected
        }
    }

    #[Test]
    public function it_includes_service_name_in_invalid_api_response_exception(): void
    {
        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow(new DateMalformedStringException('Bad date'));

        try {
            $this->client->verifyConnectivity();
            $this->fail('Expected InvalidApiResponseException');
        } catch (InvalidApiResponseException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertStringContainsString('malformed date', $e->getMessage());
        }
    }

    #[Test]
    public function it_preserves_original_exception_as_previous(): void
    {
        $originalException = new DateMalformedStringException('Original error');

        $this->sessionManager->shouldReceive('getSession')
            ->once()
            ->andThrow($originalException);

        try {
            $this->client->verifyConnectivity();
            $this->fail('Expected InvalidApiResponseException');
        } catch (InvalidApiResponseException $e) {
            $this->assertSame($originalException, $e->getPrevious());
        }
    }
}
