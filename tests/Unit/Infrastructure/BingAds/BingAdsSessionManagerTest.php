<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\BingAds\BingAdsConfig;
use App\Infrastructure\BingAds\BingAdsSession;
use App\Infrastructure\BingAds\BingAdsSessionManager;
use DateTimeImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BingAdsSessionManager Unit Tests.
 *
 * Tests OAuth session management including:
 * - Cache lookup and storage
 * - Atomic locking for concurrent requests
 * - OAuth token refresh
 * - Error handling and exception translation
 */
#[CoversClass(BingAdsSessionManager::class)]
final class BingAdsSessionManagerTest extends TestCase
{
    private BingAdsConfig $config;
    private CacheManager&MockInterface $mockCache;
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

        $this->mockCache = Mockery::mock(CacheManager::class);
        $this->manager = new BingAdsSessionManager($this->config, $this->mockCache);
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Hit Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_cached_session_when_valid(): void
    {
        $cachedSession = new BingAdsSession('cached-token', new DateTimeImmutable('+1 hour'));

        $this->mockCache
            ->shouldReceive('get')
            ->once()
            ->with('bingads:session')
            ->andReturn($cachedSession);

        $result = $this->manager->getSession();

        $this->assertSame($cachedSession, $result);
    }

    #[Test]
    public function it_refreshes_when_cached_session_is_expired(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'new-token',
                'expires_in' => 3600,
            ]),
        ]);

        $expiredSession = new BingAdsSession('expired-token', new DateTimeImmutable('-1 second'));
        $mockLock = $this->createMockLock();

        $this->mockCache
            ->shouldReceive('get')
            ->with('bingads:session')
            ->andReturn($expiredSession, null); // First call returns expired, second (after lock) returns null

        $this->mockCache
            ->shouldReceive('lock')
            ->with('bingads:session:lock', 30)
            ->andReturn($mockLock);

        $this->mockCache
            ->shouldReceive('put')
            ->once()
            ->withArgs(static fn(string $key, BingAdsSession $session, int $ttl): bool => $key === 'bingads:session'
                    && $session->accessToken === 'new-token'
                    && $ttl > 0);

        $result = $this->manager->getSession();

        $this->assertSame('new-token', $result->accessToken);
    }

    #[Test]
    public function it_refreshes_when_cache_is_empty(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $mockLock = $this->createMockLock();

        $this->mockCache
            ->shouldReceive('get')
            ->with('bingads:session')
            ->andReturn(null, null); // Both calls return null

        $this->mockCache
            ->shouldReceive('lock')
            ->andReturn($mockLock);

        $this->mockCache
            ->shouldReceive('put')
            ->once();

        $result = $this->manager->getSession();

        $this->assertSame('fresh-token', $result->accessToken);
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

        $mockLock = $this->createMockLock();

        $this->mockCache
            ->shouldReceive('get')
            ->with('bingads:session')
            ->andReturn(null, null);

        $this->mockCache
            ->shouldReceive('lock')
            ->andReturn($mockLock);

        $this->mockCache
            ->shouldReceive('put')
            ->once();

        $this->manager->getSession();

        // Verify request was sent to OAuth endpoint
        Http::assertSent(static fn(Request $request): bool => \str_contains($request->url(), 'login.microsoftonline.com')
                && $request->method() === 'POST');
    }

    /*
    |--------------------------------------------------------------------------
    | Lock Contention Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_fresh_session_when_another_request_refreshed_during_lock(): void
    {
        $freshSession = new BingAdsSession('fresh-token', new DateTimeImmutable('+1 hour'));
        $mockLock = $this->createMockLock();

        $this->mockCache
            ->shouldReceive('get')
            ->with('bingads:session')
            ->andReturn(null, $freshSession); // Second call (after lock) returns fresh session

        $this->mockCache
            ->shouldReceive('lock')
            ->andReturn($mockLock);

        // No HTTP call should be made - another request already refreshed
        Http::fake([
            '*' => Http::response([], 500), // Would fail if called
        ]);

        $result = $this->manager->getSession();

        $this->assertSame($freshSession, $result);
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

        $this->setupRefreshScenario();

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

        $this->setupRefreshScenario();

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

        $this->setupRefreshScenario();

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

        $this->setupRefreshScenario();

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

        $this->setupRefreshScenario();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Bing Ads' is unavailable");

        $this->manager->getSession();
    }

    /*
    |--------------------------------------------------------------------------
    | Invalidate Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_clears_cache_on_invalidate(): void
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
     * Create a mock lock that successfully acquires and releases.
     */
    private function createMockLock(): Lock&MockInterface
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->with(10)->andReturn(true);
        $lock->shouldReceive('release');

        return $lock;
    }

    /**
     * Setup cache mock for refresh scenarios (empty cache, acquire lock).
     */
    private function setupRefreshScenario(): void
    {
        $mockLock = $this->createMockLock();

        $this->mockCache
            ->shouldReceive('get')
            ->with('bingads:session')
            ->andReturn(null, null);

        $this->mockCache
            ->shouldReceive('lock')
            ->andReturn($mockLock);
    }
}
