<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks;

use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\QuickBooks\Results\QuickBooksDisconnectResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Application\QuickBooks\UseCases\DisconnectQuickBooksUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use Carbon\CarbonImmutable;
use Closure;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Throwable;

#[CoversClass(DisconnectQuickBooksUseCase::class)]
final class DisconnectQuickBooksUseCaseTest extends TestCase
{
    private QuickBooksOAuthClientInterface&MockInterface $oauth;

    private QuickBooksConnectionRepositoryInterface&MockInterface $connections;

    private QuickBooksAccessTokenCacheInterface&MockInterface $accessTokens;

    private LockManagerInterface&MockInterface $locks;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->oauth = Mockery::mock(QuickBooksOAuthClientInterface::class);
        $this->connections = Mockery::mock(QuickBooksConnectionRepositoryInterface::class);
        $this->accessTokens = Mockery::mock(QuickBooksAccessTokenCacheInterface::class);
        $this->locks = Mockery::mock(LockManagerInterface::class);
        $this->locks->shouldReceive('withLock')
            ->with(LockName::QuickBooksTokenRefresh->value, QuickBooksTokenService::LOCK_TIMEOUT_SECONDS, Mockery::type(Closure::class))
            ->andReturnUsing(static fn(string $name, int $timeout, Closure $callback): mixed => $callback());
    }

    #[Test]
    public function a_successful_revoke_deletes_the_connection_and_forgets_the_access_token(): void
    {
        $this->connections->shouldReceive('find')->once()->andReturn(self::connection());
        $this->oauth->shouldReceive('revoke')->once()->with(Mockery::on(static fn(ApiKeyToken $t): bool => $t->value === 'refresh-0'));
        $this->expectRemoval();

        $this->assertSame(QuickBooksDisconnectResult::Revoked, $this->useCase()->execute(force: false, dryRun: false));
    }

    #[Test]
    public function no_connection_is_reported_without_revoking_or_deleting(): void
    {
        $this->connections->shouldReceive('find')->once()->andReturnNull();
        $this->oauth->shouldNotReceive('revoke');
        $this->connections->shouldNotReceive('delete');

        $this->assertSame(QuickBooksDisconnectResult::NotConnected, $this->useCase()->execute(force: true, dryRun: false));
    }

    #[Test]
    public function a_dry_run_neither_revokes_nor_deletes(): void
    {
        $this->connections->shouldReceive('find')->once()->andReturn(self::connection());
        $this->oauth->shouldNotReceive('revoke');
        $this->connections->shouldNotReceive('delete');
        $this->accessTokens->shouldNotReceive('forget');

        $this->assertSame(QuickBooksDisconnectResult::DryRun, $this->useCase()->execute(force: true, dryRun: true));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function revokeFailures(): iterable
    {
        yield 'transient' => [new ExternalServiceUnavailableException('QuickBooks')];
        yield 'auth' => [new AuthenticationExpiredException('QuickBooks', 'rejected')];
    }

    #[Test]
    #[DataProvider('revokeFailures')]
    public function a_failed_revoke_without_force_rethrows_and_keeps_the_connection(Throwable $failure): void
    {
        $this->connections->shouldReceive('find')->once()->andReturn(self::connection());
        $this->oauth->shouldReceive('revoke')->once()->andThrow($failure);
        $this->connections->shouldNotReceive('delete');
        $this->accessTokens->shouldNotReceive('forget');

        $this->expectExceptionObject($failure);

        $this->useCase()->execute(force: false, dryRun: false);
    }

    #[Test]
    #[DataProvider('revokeFailures')]
    public function a_failed_revoke_with_force_still_removes_the_connection(Throwable $failure): void
    {
        $this->connections->shouldReceive('find')->once()->andReturn(self::connection());
        $this->oauth->shouldReceive('revoke')->once()->andThrow($failure);
        $this->expectRemoval();

        $this->assertSame(QuickBooksDisconnectResult::RemovedWithoutRevoke, $this->useCase()->execute(force: true, dryRun: false));
    }

    #[Test]
    public function an_unreadable_connection_without_force_rethrows(): void
    {
        $failure = new CorruptApiKeyException();
        $this->connections->shouldReceive('find')->once()->andThrow($failure);
        $this->connections->shouldNotReceive('delete');

        $this->expectExceptionObject($failure);

        $this->useCase()->execute(force: false, dryRun: false);
    }

    #[Test]
    public function an_unreadable_connection_with_force_is_removed_without_revoking(): void
    {
        $this->connections->shouldReceive('find')->once()->andThrow(new CorruptApiKeyException());
        $this->oauth->shouldNotReceive('revoke');
        $this->expectRemoval();

        $this->assertSame(QuickBooksDisconnectResult::RemovedWithoutRevoke, $this->useCase()->execute(force: true, dryRun: false));
    }

    #[Test]
    public function an_unreadable_connection_on_a_forced_dry_run_is_kept(): void
    {
        $this->connections->shouldReceive('find')->once()->andThrow(new CorruptApiKeyException());
        $this->connections->shouldNotReceive('delete');

        $this->assertSame(QuickBooksDisconnectResult::DryRun, $this->useCase()->execute(force: true, dryRun: true));
    }

    private function expectRemoval(): void
    {
        $this->connections->shouldReceive('delete')->once()->ordered();
        $this->accessTokens->shouldReceive('forget')->once()->ordered();
    }

    private function useCase(): DisconnectQuickBooksUseCase
    {
        $logger = new NullLogger();
        $tokens = new QuickBooksTokenService($this->oauth, $this->connections, $this->accessTokens, $this->locks, $logger);

        return new DisconnectQuickBooksUseCase($this->oauth, $this->connections, $tokens, $this->locks, $logger);
    }

    private static function connection(): QuickBooksConnection
    {
        return new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-0'),
            refreshTokenExpiresAt: new CarbonImmutable('2027-02-26T10:00:00+00:00'),
            refreshTokenHardExpiresAt: null,
        );
    }
}
