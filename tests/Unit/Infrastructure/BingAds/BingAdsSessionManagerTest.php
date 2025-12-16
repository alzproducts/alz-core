<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Application\Contracts\LockableCacheInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\BingAds\BingAdsConfig;
use App\Infrastructure\BingAds\BingAdsSession;
use App\Infrastructure\BingAds\BingAdsSessionManager;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * BingAdsSessionManager Unit Tests.
 *
 * Tests OAuth session management including:
 * - Cache delegation to LockableCacheInterface
 * - OAuth token refresh
 * - Error handling and exception translation
 *
 * Note: Lock contention tests are now in LockableCacheTest since the manager
 * delegates locking to LockableCache via the remember() method.
 */
#[CoversClass(BingAdsSessionManager::class)]
final class BingAdsSessionManagerTest extends TestCase
{
    private BingAdsConfig $config;
    private LockableCacheInterface&MockInterface $mockCache;
    private BingAdsSessionManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new BingAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            accountId: '12345678',
            customerId: '87654321',
        );

        $this->mockCache = Mockery::mock(LockableCacheInterface::class);
        $this->manager = new BingAdsSessionManager($this->config, $this->mockCache);
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Delegation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_cache_remember_with_correct_parameters(): void
    {
        $expectedSession = new BingAdsSession('cached-token', new DateTimeImmutable('+1 hour'));

        $this->mockCache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static function (string $key, Closure $factory, int $ttl, ?Closure $validator): bool {
                // Verify key
                if ($key !== 'bingads:session') {
                    return false;
                }

                // Verify TTL (default 3600)
                if ($ttl !== 3600) {
                    return false;
                }

                // Verify validator accepts valid session
                $validSession = new BingAdsSession('token', new DateTimeImmutable('+1 hour'));
                if ($validator !== null && !$validator($validSession)) {
                    return false;
                }

                // Verify validator rejects expired session
                $expiredSession = new BingAdsSession('token', new DateTimeImmutable('-1 second'));
                if ($validator !== null && $validator($expiredSession)) {
                    return false;
                }

                // Verify validator rejects non-session
                return ! ($validator !== null && $validator('not-a-session'))



                ;
            })
            ->andReturn($expectedSession);

        $result = $this->manager->getSession();

        $this->assertSame($expectedSession, $result);
    }

    #[Test]
    public function it_executes_oauth_refresh_when_factory_is_called(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'new-token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->mockCache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(function (string $key, Closure $factory, int $ttl, ?Closure $validator): bool {
                // Execute the factory to trigger OAuth call
                $session = $factory();
                $this->assertInstanceOf(BingAdsSession::class, $session);
                $this->assertSame('new-token', $session->accessToken);

                return true;
            })
            ->andReturnUsing(static fn($key, $factory) => $factory());

        $result = $this->manager->getSession();

        $this->assertSame('new-token', $result->accessToken);
    }

    #[Test]
    public function it_sends_oauth_request_to_correct_endpoint(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'new-token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->mockCache
            ->shouldReceive('remember')
            ->andReturnUsing(static fn($key, $factory) => $factory());

        $this->manager->getSession();

        Http::assertSent(static fn(Request $request): bool => \str_contains($request->url(), 'login.microsoftonline.com')
                && $request->method() === 'POST');
    }

    /*
    |--------------------------------------------------------------------------
    | OAuth Error Handling Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_authentication_expired_on_http_400(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Refresh token is expired',
            ], 400),
        ]);

        $this->setupFactoryExecution();

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials or refresh token expired');

        $this->manager->getSession();
    }

    #[Test]
    public function it_throws_authentication_expired_on_http_401(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'unauthorized_client',
            ], 401),
        ]);

        $this->setupFactoryExecution();

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials or refresh token expired');

        $this->manager->getSession();
    }

    #[Test]
    public function it_throws_authentication_expired_on_http_403(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'access_denied',
            ], 403),
        ]);

        $this->setupFactoryExecution();

        $this->expectException(AuthenticationExpiredException::class);

        $this->manager->getSession();
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_http_500(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'server_error',
            ], 500),
        ]);

        $this->setupFactoryExecution();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Bing Ads' is unavailable");

        $this->manager->getSession();
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_connection_error(): void
    {
        Http::fake(static function (): void {
            throw new ConnectionException('Connection timed out');
        });

        $this->setupFactoryExecution();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Bing Ads' is unavailable");

        $this->manager->getSession();
    }

    /*
    |--------------------------------------------------------------------------
    | Log Assertion Tests (Mutation Testing)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_authentication_failure_with_service_name_and_context(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Refresh token is expired',
            ], 400),
        ]);

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Bing Ads') && \str_contains($msg, 'OAuth authentication failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 400
                        && isset($ctx['error'])
                        && \array_key_exists('response', $ctx)),
            );

        try {
            $this->manager->getSession();
        } catch (AuthenticationExpiredException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_oauth_endpoint_error_with_service_name_and_status(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'server_error',
            ], 500),
        ]);

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Bing Ads') && \str_contains($msg, 'OAuth endpoint error')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 500
                        && isset($ctx['error'])),
            );

        try {
            $this->manager->getSession();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_connection_error_with_service_name_and_message(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out');
        });

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Bing Ads') && \str_contains($msg, 'OAuth connection failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['error']) && \str_contains($ctx['error'], 'Connection timed out')),
            );

        try {
            $this->manager->getSession();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_unexpected_error_with_service_name_and_exception_class(): void
    {
        Http::fake(static function (): never {
            throw new RuntimeException('Unexpected error');
        });

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Bing Ads') && \str_contains($msg, 'OAuth unexpected error')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['exception']) && $ctx['exception'] === RuntimeException::class
                        && isset($ctx['error'])),
            );

        try {
            $this->manager->getSession();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Invalidate Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_invalidate_to_cache_forget(): void
    {
        $this->mockCache
            ->shouldReceive('forget')
            ->once()
            ->with('bingads:session');

        $this->manager->invalidate();

        // Assert no exception thrown
        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Setup cache mock to execute factory (triggers OAuth call).
     */
    private function setupFactoryExecution(): void
    {
        $this->mockCache
            ->shouldReceive('remember')
            ->andReturnUsing(static fn($key, $factory) => $factory());
    }
}
