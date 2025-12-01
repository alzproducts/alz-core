<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateTimeImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LinnworksSessionManager Unit Tests.
 *
 * Tests the session lifecycle management for Linnworks API:
 * - Cache-first session retrieval
 * - Atomic lock for concurrent authentication prevention
 * - Double-check after lock acquisition
 * - Lock timeout fallback behavior
 * - Authentication with Linnworks auth endpoint
 * - Session caching with calculated TTL
 * - Exception translation for auth failures
 */
#[CoversClass(LinnworksSessionManager::class)]
final class LinnworksSessionManagerTest extends TestCase
{
    private const string TEST_TOKEN = 'test-session-token';
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';
    private const int TEST_TTL = 86400;

    private LinnworksConfig $config;
    private MockInterface&CacheManager $cache;
    private LinnworksSessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new LinnworksConfig(
            applicationId: 'test-app-id',
            applicationSecret: 'test-app-secret',
            installationToken: 'test-install-token',
        );

        $this->cache = Mockery::mock(CacheManager::class);
        $this->manager = new LinnworksSessionManager($this->config, $this->cache);
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Hit Tests (No Authentication Required)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_cached_session_when_valid(): void
    {
        $cachedSession = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->cache->shouldReceive('get')
            ->once()
            ->with('linnworks:session')
            ->andReturn($cachedSession);

        // Lock should never be called if cache hit
        $this->cache->shouldNotReceive('lock');

        $result = $this->manager->getSession();

        $this->assertSame($cachedSession, $result);
    }

    #[Test]
    public function it_authenticates_when_cached_session_is_expired(): void
    {
        $expiredSession = new LinnworksSession(
            token: 'old-token',
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('-1 second'),
        );

        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn($expiredSession, null); // First call returns expired, second (after lock) returns null

        $this->setupLockMock(acquires: true);
        $this->setupSuccessfulAuthResponse();

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(static fn(string $key, LinnworksSession $session, int $ttl): bool => $key === 'linnworks:session'
                    && $session->token === self::TEST_TOKEN
                    && $ttl > 0);

        $result = $this->manager->getSession();

        $this->assertSame(self::TEST_TOKEN, $result->token);
    }

    #[Test]
    public function it_authenticates_when_cache_is_empty(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null); // Both before and after lock

        $this->setupLockMock(acquires: true);
        $this->setupSuccessfulAuthResponse();

        $this->cache->shouldReceive('put')
            ->once()
            ->with('linnworks:session', Mockery::type(LinnworksSession::class), Mockery::type('int'));

        $result = $this->manager->getSession();

        $this->assertSame(self::TEST_TOKEN, $result->token);
        $this->assertSame(self::TEST_SERVER_URL, $result->serverUrl);
    }

    /*
    |--------------------------------------------------------------------------
    | Lock Behavior Tests (Thundering Herd Prevention)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_session_if_another_request_authenticated_during_lock_wait(): void
    {
        // First cache check: empty (triggers authentication flow)
        // Second cache check (after lock): valid session (another request authenticated)
        $freshSession = new LinnworksSession(
            token: 'fresh-token',
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, $freshSession); // Before lock: null, after lock: fresh

        $this->setupLockMock(acquires: true);

        // No HTTP calls should be made - another request already authenticated
        Http::fake(fn() => $this->fail('HTTP should not be called'));

        // No cache put should happen
        $this->cache->shouldNotReceive('put');

        $result = $this->manager->getSession();

        $this->assertSame('fresh-token', $result->token);
    }

    #[Test]
    public function it_falls_back_to_direct_auth_when_lock_times_out(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null);

        // Lock times out
        $this->setupLockMock(acquires: false);

        $this->setupSuccessfulAuthResponse();

        $this->cache->shouldReceive('put')
            ->once()
            ->with('linnworks:session', Mockery::type(LinnworksSession::class), Mockery::type('int'));

        $result = $this->manager->getSession();

        $this->assertSame(self::TEST_TOKEN, $result->token);
    }

    /*
    |--------------------------------------------------------------------------
    | Session Invalidation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_invalidates_cached_session(): void
    {
        $this->cache->shouldReceive('forget')
            ->once()
            ->with('linnworks:session');

        $this->manager->invalidate();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Error Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('authenticationFailureStatusCodes')]
    public function it_throws_authentication_exception_for_auth_failure(int $statusCode): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null);

        $this->setupLockMock(acquires: true);

        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response(['error' => 'Unauthorized'], $statusCode),
        ]);

        try {
            $this->manager->getSession();
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertStringContainsString('Invalid credentials', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function authenticationFailureStatusCodes(): array
    {
        return [
            '401 Unauthorized' => [401],
            '403 Forbidden' => [403],
        ];
    }

    #[Test]
    public function it_throws_service_unavailable_for_server_error(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null);

        $this->setupLockMock(acquires: true);

        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response(['error' => 'Server Error'], 500),
        ]);

        try {
            $this->manager->getSession();
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
        }
    }

    #[Test]
    public function it_throws_service_unavailable_for_connection_failure(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null);

        $this->setupLockMock(acquires: true);

        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });

        try {
            $this->manager->getSession();
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Session TTL Caching Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_caches_session_with_calculated_ttl(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null);

        $this->setupLockMock(acquires: true);

        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => self::TEST_TOKEN,
                'Server' => self::TEST_SERVER_URL,
                'TTL' => 3600, // 1 hour
            ], 200),
        ]);

        // Expect TTL to be 3600 - 300 (default buffer) = 3300, give or take a few seconds
        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(static function (string $key, LinnworksSession $session, int $ttl): bool {
                // TTL should be approximately 3300 seconds (3600 - 300 buffer)
                return $key === 'linnworks:session'
                    && $ttl >= 3295
                    && $ttl <= 3305;
            });

        $this->manager->getSession();
    }

    #[Test]
    public function it_ensures_minimum_cache_ttl_of_one_second(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null);

        $this->setupLockMock(acquires: true);

        // Very short TTL that would go negative after buffer subtraction
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => self::TEST_TOKEN,
                'Server' => self::TEST_SERVER_URL,
                'TTL' => 100, // Less than default buffer
            ], 200),
        ]);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(static function (string $key, LinnworksSession $session, int $ttl): bool {
                // Should be max(1, negative) = 1
                return $key === 'linnworks:session' && $ttl >= 1;
            });

        $this->manager->getSession();
    }

    /*
    |--------------------------------------------------------------------------
    | Auth Request Format Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sends_credentials_in_correct_format(): void
    {
        $this->cache->shouldReceive('get')
            ->with('linnworks:session')
            ->andReturn(null, null);

        $this->setupLockMock(acquires: true);

        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => self::TEST_TOKEN,
                'Server' => self::TEST_SERVER_URL,
                'TTL' => self::TEST_TTL,
            ], 200),
        ]);

        $this->cache->shouldReceive('put')->once();

        $this->manager->getSession();

        Http::assertSent(static function ($request) {
            $formData = $request->data();

            return $request->url() === LinnworksConfig::AUTH_URL
                && $formData['applicationId'] === 'test-app-id'
                && $formData['applicationSecret'] === 'test-app-secret'
                && $formData['token'] === 'test-install-token';
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function setupLockMock(bool $acquires): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->with(10) // LOCK_WAIT_SECONDS
            ->andReturn($acquires);

        if ($acquires) {
            $lock->shouldReceive('release')->once();
        }

        $this->cache->shouldReceive('lock')
            ->with('linnworks:session:lock', 30) // LOCK_TIMEOUT_SECONDS
            ->andReturn($lock);
    }

    private function setupSuccessfulAuthResponse(): void
    {
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => self::TEST_TOKEN,
                'Server' => self::TEST_SERVER_URL,
                'TTL' => self::TEST_TTL,
            ], 200),
        ]);
    }
}
