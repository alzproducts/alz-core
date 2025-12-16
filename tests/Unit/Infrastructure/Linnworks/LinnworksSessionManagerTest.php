<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks;

use App\Application\Contracts\LockableCacheInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LinnworksSessionManager Unit Tests.
 *
 * Tests session management including:
 * - Cache delegation to LockableCacheInterface
 * - Authentication with Linnworks auth endpoint
 * - Error handling and exception translation
 *
 * Note: Lock contention tests are now in LockableCacheTest since the manager
 * delegates locking to LockableCache via the remember() method.
 */
#[CoversClass(LinnworksSessionManager::class)]
final class LinnworksSessionManagerTest extends TestCase
{
    private const string TEST_TOKEN = 'test-session-token';
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';
    private const int TEST_TTL = 86400;

    private LinnworksConfig $config;
    private LockableCacheInterface&MockInterface $mockCache;
    private LinnworksSessionManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new LinnworksConfig(
            applicationId: 'test-app-id',
            applicationSecret: 'test-app-secret',
            installationToken: 'test-install-token',
        );

        $this->mockCache = Mockery::mock(LockableCacheInterface::class);
        $this->manager = new LinnworksSessionManager($this->config, $this->mockCache);
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Delegation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_cache_remember_with_correct_parameters(): void
    {
        $expectedSession = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->mockCache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(static function (string $key, Closure $factory, int $ttl, ?Closure $validator): bool {
                // Verify key
                if ($key !== 'linnworks:session') {
                    return false;
                }

                // Verify TTL (default 3600)
                if ($ttl !== 3600) {
                    return false;
                }

                // Verify validator accepts valid session
                $validSession = new LinnworksSession(
                    self::TEST_TOKEN,
                    self::TEST_SERVER_URL,
                    new DateTimeImmutable('+1 hour'),
                );
                if ($validator !== null && !$validator($validSession)) {
                    return false;
                }

                // Verify validator rejects expired session
                $expiredSession = new LinnworksSession(
                    self::TEST_TOKEN,
                    self::TEST_SERVER_URL,
                    new DateTimeImmutable('-1 second'),
                );
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
    public function it_executes_authentication_when_factory_is_called(): void
    {
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => 'new-token',
                'Server' => self::TEST_SERVER_URL,
                'TTL' => self::TEST_TTL,
            ]),
        ]);

        $this->mockCache
            ->shouldReceive('remember')
            ->once()
            ->withArgs(function (string $key, Closure $factory, int $ttl, ?Closure $validator): bool {
                // Execute the factory to trigger auth call
                $session = $factory();
                $this->assertInstanceOf(LinnworksSession::class, $session);
                $this->assertSame('new-token', $session->token);

                return true;
            })
            ->andReturnUsing(static fn($key, $factory) => $factory());

        $result = $this->manager->getSession();

        $this->assertSame('new-token', $result->token);
    }

    #[Test]
    public function it_sends_auth_request_to_correct_endpoint(): void
    {
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => self::TEST_TOKEN,
                'Server' => self::TEST_SERVER_URL,
                'TTL' => self::TEST_TTL,
            ]),
        ]);

        $this->mockCache
            ->shouldReceive('remember')
            ->andReturnUsing(static fn($key, $factory) => $factory());

        $this->manager->getSession();

        Http::assertSent(static fn(Request $request): bool => $request->url() === LinnworksConfig::AUTH_URL
                && $request->method() === 'POST');
    }

    #[Test]
    public function it_sends_credentials_in_correct_format(): void
    {
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response([
                'Token' => self::TEST_TOKEN,
                'Server' => self::TEST_SERVER_URL,
                'TTL' => self::TEST_TTL,
            ]),
        ]);

        $this->mockCache
            ->shouldReceive('remember')
            ->andReturnUsing(static fn($key, $factory) => $factory());

        $this->manager->getSession();

        Http::assertSent(static function ($request) {
            $formData = $request->data();

            return $formData['applicationId'] === 'test-app-id'
                && $formData['applicationSecret'] === 'test-app-secret'
                && $formData['token'] === 'test-install-token';
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Error Handling Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('authenticationFailureStatusCodes')]
    public function it_throws_authentication_exception_for_auth_failure(int $statusCode): void
    {
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response(['error' => 'Unauthorized'], $statusCode),
        ]);

        $this->setupFactoryExecution();

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->manager->getSession();
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
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response(['error' => 'Server Error'], 500),
        ]);

        $this->setupFactoryExecution();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Linnworks' is unavailable");

        $this->manager->getSession();
    }

    #[Test]
    public function it_throws_service_unavailable_for_connection_failure(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });

        $this->setupFactoryExecution();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Linnworks' is unavailable");

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
            LinnworksConfig::AUTH_URL => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Linnworks') && \str_contains($msg, 'authentication failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 401
                        && isset($ctx['error'])),
            );

        try {
            $this->manager->getSession();
        } catch (AuthenticationExpiredException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_auth_endpoint_error_with_service_name_and_status(): void
    {
        Http::fake([
            LinnworksConfig::AUTH_URL => Http::response(['error' => 'Server Error'], 500),
        ]);

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Linnworks') && \str_contains($msg, 'auth endpoint error')),
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
            throw new ConnectionException('Connection refused');
        });

        $this->setupFactoryExecution();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Linnworks') && \str_contains($msg, 'auth connection failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['error']) && \str_contains($ctx['error'], 'Connection refused')),
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
            ->with('linnworks:session');

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
     * Setup cache mock to execute factory (triggers auth call).
     */
    private function setupFactoryExecution(): void
    {
        $this->mockCache
            ->shouldReceive('remember')
            ->andReturnUsing(static fn($key, $factory) => $factory());
    }
}
