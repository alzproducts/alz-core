<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\HelpScoutErrorHandler;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Http\Authenticator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Integration tests for HelpScoutHttpTransport OAuth refresh-then-retry behaviour.
 *
 * Drives the HTTP boundary with Http::fake() sequential responses, and mocks the
 * SDK Authenticator to assert the token re-mint (fetchAccessAndRefreshToken) fires
 * exactly when expected — without hitting the real OAuth endpoint.
 */
#[CoversClass(HelpScoutHttpTransport::class)]
#[Group('integration')]
final class HelpScoutHttpTransportTest extends TestCase
{
    private Authenticator&MockInterface $authenticator;

    private HelpScoutHttpTransport $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = Mockery::mock(Authenticator::class);
        $this->authenticator->allows('getAuthHeader')->andReturn(['Authorization' => 'Bearer test-token']);

        $sdkClient = Mockery::mock(ApiClient::class);
        $sdkClient->allows('getAuthenticator')->andReturn($this->authenticator);

        $config = new HelpScoutConfig(
            mailboxes: ['support' => 1],
            timeoutSeconds: 30,
            retryAttempts: 3,
        );

        // Http::getFacadeRoot() returns the exact Factory instance Http::fake() records on,
        // so the transport's injected HttpFactory is intercepted by the fakes below.
        /** @var HttpFactory $factory */
        $factory = Http::getFacadeRoot();

        $this->transport = new HelpScoutHttpTransport($config, $sdkClient, $factory, new HelpScoutErrorHandler(\app(TransientLogThrottle::class)));
    }

    /*
    |--------------------------------------------------------------------------
    | get() — single request
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refreshes_token_and_retries_once_on_401_then_succeeds(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->once();

        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'Unauthorized'], 401)
                ->push(['id' => 123], 200),
        ]);

        $response = $this->transport->get('/conversations/123');

        $this->assertSame(200, $response->status());
        $this->assertSame(['id' => 123], $response->json());
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_throws_authentication_expired_when_second_401_after_refresh(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->once();

        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'Unauthorized'], 401)
                ->push(['error' => 'Unauthorized'], 401),
        ]);

        try {
            $this->transport->get('/conversations/123');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Invalid credentials', $e->detail);
            Http::assertSentCount(2);
        }
    }

    #[Test]
    public function it_does_not_refresh_on_403_and_throws_authentication_expired(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->never();

        Http::fake(['*' => Http::response(['error' => 'Forbidden'], 403)]);

        try {
            $this->transport->get('/conversations/123');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Insufficient permissions', $e->detail);
        }
    }

    #[Test]
    public function it_does_not_refresh_on_non_auth_error_and_throws_service_unavailable(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->never();

        Http::fake(['*' => Http::response(['error' => 'Server Error'], 500)]);

        try {
            $this->transport->get('/conversations/123');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('HelpScout', $e->serviceName);
        }
    }

    #[Test]
    public function it_throws_token_refresh_failed_when_the_refresh_itself_fails(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->once()
            ->andThrow(new RuntimeException('OAuth endpoint down'));

        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        try {
            $this->transport->get('/conversations/123');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Token refresh failed', $e->detail);
            Http::assertSentCount(1);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | poolGet() — concurrent requests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refreshes_token_and_retries_pool_once_on_401_then_succeeds(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->once();

        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'Unauthorized'], 401)
                ->push(['_embedded' => ['conversations' => []]], 200),
        ]);

        $results = $this->transport->poolGet(['inbox' => ['mailbox' => 1]]);

        $this->assertArrayHasKey('inbox', $results);
        $this->assertSame(200, $results['inbox']->status());
    }

    #[Test]
    public function it_does_not_refresh_pool_when_all_requests_succeed(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->never();

        Http::fake(['*' => Http::response(['_embedded' => ['conversations' => []]], 200)]);

        $results = $this->transport->poolGet(['inbox' => ['mailbox' => 1]]);

        $this->assertSame(200, $results['inbox']->status());
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_throws_authentication_expired_when_pool_still_401_after_refresh(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->once();

        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        try {
            $this->transport->poolGet(['inbox' => ['mailbox' => 1]]);
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Invalid credentials', $e->detail);
            Http::assertSentCount(2);
        }
    }

    #[Test]
    public function it_does_not_refresh_pool_on_403_and_throws_authentication_expired(): void
    {
        $this->authenticator->shouldReceive('fetchAccessAndRefreshToken')->never();

        Http::fake(['*' => Http::response(['error' => 'Forbidden'], 403)]);

        try {
            $this->transport->poolGet(['inbox' => ['mailbox' => 1]]);
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Insufficient permissions', $e->detail);
        }
    }
}
