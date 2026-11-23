<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\Services;

use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\QuickBooks\ValueObjects\QuickBooksTokenGrant;
use Closure;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Drives the service over stateful contract doubles: the cache, the stored connection and Intuit's
 * rotation are modelled so each test asserts which refresh tokens were actually sent.
 */
#[CoversClass(QuickBooksTokenService::class)]
final class QuickBooksTokenServiceTest extends TestCase
{
    private ?ApiKeyToken $cachedAccessToken = null;

    private ?DateTimeImmutable $cachedExpiresAt = null;

    private ?DateTimeImmutable $grantedExpiresAt = null;

    private int $lockHolds = 0;

    private ?QuickBooksConnection $storedConnection = null;

    /** @var list<string> */
    private array $sentRefreshTokens = [];

    private bool $saveFails = false;

    private bool $lockFails = false;

    private bool $cachePutFails = false;

    /** @var (Closure(): void)|null */
    private ?Closure $whileWaitingForLock = null;

    private LoggerInterface&MockInterface $logger;

    private QuickBooksTokenService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->storedConnection = self::connection('refresh-0');
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->service = new QuickBooksTokenService(
            oauthClient: $this->oauthClient(),
            connections: $this->connections(),
            accessTokens: $this->accessTokens(),
            lockManager: $this->lockManager(),
            logger: $this->logger,
        );
    }

    #[Test]
    public function it_stores_the_rotated_refresh_token_and_sends_it_on_the_next_refresh(): void
    {
        $this->assertSame('access-1', $this->service->forceRefresh()->value);
        $this->assertSame('refresh-1', $this->storedConnection?->refreshToken->value);

        $this->assertSame('access-2', $this->service->forceRefresh()->value);

        $this->assertSame(['refresh-0', 'refresh-1'], $this->sentRefreshTokens);
        $this->assertSame('refresh-2', $this->storedConnection?->refreshToken->value);
    }

    #[Test]
    public function two_calls_within_the_access_token_lifetime_send_one_token_request(): void
    {
        $first = $this->service->accessToken();
        $second = $this->service->accessToken();

        $this->assertSame('access-1', $first->value);
        $this->assertSame('access-1', $second->value);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    #[Test]
    public function a_contender_that_waited_on_the_lock_uses_the_holders_token_without_sending_a_request(): void
    {
        $this->whileWaitingForLock = function (): void {
            $this->whileWaitingForLock = null;
            $this->service->accessToken();
        };

        $token = $this->service->accessToken();

        $this->assertSame('access-1', $token->value);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    #[Test]
    public function a_forced_refresh_after_another_holder_sends_that_holders_stored_token(): void
    {
        $this->whileWaitingForLock = function (): void {
            $this->whileWaitingForLock = null;
            $this->service->forceRefresh();
        };

        $this->service->forceRefresh();

        $this->assertSame(['refresh-0', 'refresh-1'], $this->sentRefreshTokens);
        $this->assertSame('refresh-2', $this->storedConnection?->refreshToken->value);
    }

    #[Test]
    public function a_forced_refresh_sends_a_request_even_with_a_cached_access_token(): void
    {
        $this->cachedAccessToken = new ApiKeyToken('still-valid');

        $this->assertSame('access-1', $this->service->forceRefresh()->value);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    #[Test]
    public function a_lock_failure_sends_no_request_and_keeps_the_stored_token(): void
    {
        $this->lockFails = true;

        try {
            $this->service->accessToken();
            $this->fail('Expected LockAcquisitionException');
        } catch (LockAcquisitionException $e) {
            $this->assertSame(LockName::QuickBooksTokenRefresh->value, $e->lockName);
            $this->assertSame(QuickBooksTokenService::LOCK_TIMEOUT_SECONDS, $e->timeoutSeconds);
        }

        $this->assertSame([], $this->sentRefreshTokens);
        $this->assertSame('refresh-0', $this->storedConnection?->refreshToken->value);
        $this->assertNull($this->cachedAccessToken);
    }

    #[Test]
    public function no_stored_connection_raises_authentication_expired_without_a_request(): void
    {
        $this->storedConnection = null;

        $this->expectException(AuthenticationExpiredException::class);

        try {
            $this->service->accessToken();
        } finally {
            $this->assertSame([], $this->sentRefreshTokens);
        }
    }

    #[Test]
    public function a_failed_save_after_a_refresh_logs_critical_keeps_the_access_token_and_rethrows(): void
    {
        $this->saveFails = true;
        $this->logger->shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'quickbooks:connect')
                && $context === ['exception' => DatabaseOperationFailedException::class]);

        try {
            $this->service->forceRefresh();
            $this->fail('Expected DatabaseOperationFailedException');
        } catch (DatabaseOperationFailedException) {
        }

        $this->assertSame('access-1', $this->cachedAccessToken?->value);
        $this->assertSame('refresh-0', $this->storedConnection?->refreshToken->value);
    }

    #[Test]
    public function a_failed_access_token_cache_write_still_stores_the_rotated_refresh_token_and_rethrows(): void
    {
        $this->cachePutFails = true;

        try {
            $this->service->forceRefresh();
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException) {
        }

        $this->assertSame('refresh-1', $this->storedConnection?->refreshToken->value);
    }

    #[Test]
    public function forgetting_the_access_token_clears_the_cache(): void
    {
        $this->cachedAccessToken = new ApiKeyToken('cached');

        $this->service->forgetAccessToken();

        $this->assertNull($this->cachedAccessToken);
    }

    #[Test]
    public function a_lease_above_the_floor_returns_the_cached_token_in_one_lock_hold_without_a_request(): void
    {
        $this->cachedAccessToken = new ApiKeyToken('cached');
        // Two seconds of slack: the service reads the real clock.
        $this->cachedExpiresAt = self::secondsFromNow(QuickBooksTokenService::LEASE_FLOOR_SECONDS + 2);

        $lease = $this->service->lease();

        $this->assertSame('cached', $lease?->accessToken->value);
        $this->assertSame($this->cachedExpiresAt, $lease->expiresAt);
        $this->assertSame([], $this->sentRefreshTokens);
        $this->assertSame(1, $this->lockHolds);
    }

    #[Test]
    public function a_lease_below_the_floor_refreshes_in_the_same_lock_hold(): void
    {
        $this->cachedAccessToken = new ApiKeyToken('cached');
        $this->cachedExpiresAt = self::secondsFromNow(QuickBooksTokenService::LEASE_FLOOR_SECONDS - 1);
        $this->grantedExpiresAt = self::secondsFromNow(3600);

        $lease = $this->service->lease();

        $this->assertSame('access-1', $lease?->accessToken->value);
        $this->assertSame($this->grantedExpiresAt, $lease->expiresAt);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
        $this->assertSame('refresh-1', $this->storedConnection?->refreshToken->value);
        $this->assertSame(1, $this->lockHolds);
    }

    #[Test]
    public function a_lease_of_a_token_with_unknown_expiry_refreshes(): void
    {
        $this->cachedAccessToken = new ApiKeyToken('cached');

        $this->assertSame('access-1', $this->service->lease()?->accessToken->value);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    #[Test]
    public function a_lease_with_no_cached_token_refreshes(): void
    {
        $this->assertSame('access-1', $this->service->lease()?->accessToken->value);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    #[Test]
    public function two_concurrent_leases_below_the_floor_send_one_token_request(): void
    {
        $this->cachedAccessToken = new ApiKeyToken('cached');
        $this->cachedExpiresAt = self::secondsFromNow(60);
        $this->grantedExpiresAt = self::secondsFromNow(3600);
        $holder = null;
        $this->whileWaitingForLock = function () use (&$holder): void {
            $this->whileWaitingForLock = null;
            $holder = $this->service->lease();
        };

        $contender = $this->service->lease();

        $this->assertSame('access-1', $holder?->accessToken->value);
        $this->assertSame('access-1', $contender?->accessToken->value);
        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    #[Test]
    public function a_lease_without_a_connection_returns_nothing_even_with_a_cached_token(): void
    {
        $this->storedConnection = null;
        $this->cachedAccessToken = new ApiKeyToken('cached');
        $this->cachedExpiresAt = self::secondsFromNow(3600);

        $this->assertNull($this->service->lease());
        $this->assertSame([], $this->sentRefreshTokens);
    }

    private static function secondsFromNow(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . (\time() + $seconds));
    }

    private function oauthClient(): QuickBooksOAuthClientInterface
    {
        $client = Mockery::mock(QuickBooksOAuthClientInterface::class);
        $client->shouldReceive('refresh')->andReturnUsing(function (ApiKeyToken $refreshToken): QuickBooksTokenGrant {
            $this->sentRefreshTokens[] = $refreshToken->value;
            $n = \count($this->sentRefreshTokens);

            return new QuickBooksTokenGrant(
                accessToken: new ApiKeyToken("access-{$n}"),
                accessTokenExpiresAt: $this->grantedExpiresAt ?? new DateTimeImmutable('2026-11-18T11:00:00+00:00'),
                connection: self::connection("refresh-{$n}"),
            );
        });

        return $client;
    }

    private function connections(): QuickBooksConnectionRepositoryInterface
    {
        $repository = Mockery::mock(QuickBooksConnectionRepositoryInterface::class);
        $repository->shouldReceive('find')->andReturnUsing(fn(): ?QuickBooksConnection => $this->storedConnection);
        $repository->shouldReceive('save')->andReturnUsing(function (QuickBooksConnection $connection): void {
            if ($this->saveFails) {
                throw new DatabaseOperationFailedException('upsert', 'quickbooks_connections');
            }
            $this->storedConnection = $connection;
        });

        return $repository;
    }

    private function accessTokens(): QuickBooksAccessTokenCacheInterface
    {
        $cache = Mockery::mock(QuickBooksAccessTokenCacheInterface::class);
        $cache->shouldReceive('get')->andReturnUsing(fn(): ?ApiKeyToken => $this->cachedAccessToken);
        $cache->shouldReceive('expiresAt')->andReturnUsing(fn(): ?DateTimeImmutable => $this->cachedExpiresAt);
        $cache->shouldReceive('put')->andReturnUsing(function (ApiKeyToken $token, DateTimeImmutable $expiresAt): void {
            if ($this->cachePutFails) {
                throw new ExternalServiceUnavailableException('QuickBooks token cache');
            }
            $this->cachedAccessToken = $token;
            $this->cachedExpiresAt = $expiresAt;
        });
        $cache->shouldReceive('forget')->andReturnUsing(function (): void {
            $this->cachedAccessToken = null;
        });

        return $cache;
    }

    private function lockManager(): LockManagerInterface
    {
        $lock = Mockery::mock(LockManagerInterface::class);
        $lock->shouldReceive('withLock')->andReturnUsing(function (string $name, int $timeout, Closure $callback): mixed {
            if ($this->lockFails) {
                throw new LockAcquisitionException($name, $timeout);
            }
            if ($this->whileWaitingForLock !== null) {
                ($this->whileWaitingForLock)();
            }
            $this->lockHolds++;

            return $callback();
        });

        return $lock;
    }

    private static function connection(string $refreshToken): QuickBooksConnection
    {
        return new QuickBooksConnection(
            refreshToken: new ApiKeyToken($refreshToken),
            refreshTokenExpiresAt: new DateTimeImmutable('2027-02-26T10:00:00+00:00'),
            refreshTokenHardExpiresAt: null,
        );
    }
}
