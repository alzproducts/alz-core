<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Locking;

use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Infrastructure\Locking\CacheLockManager;
use DomainException;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(CacheLockManager::class)]
final class CacheLockManagerTest extends TestCase
{
    private CacheManager&MockInterface $cache;

    private Lock&MockInterface $lock;

    private LoggerInterface&MockInterface $logger;

    private CacheLockManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = Mockery::mock(CacheManager::class);
        $this->lock = Mockery::mock(Lock::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->manager = new CacheLockManager($this->cache, $this->logger);
    }

    #[Test]
    public function it_runs_the_callback_holding_the_lock_for_the_wait_plus_buffer_and_releases_it(): void
    {
        $this->cache->shouldReceive('lock')->once()->with('lock:test', 30)->andReturn($this->lock);
        $this->lock->shouldReceive('block')->once()->with(20)->andReturnTrue();
        $this->lock->shouldReceive('release')->once()->andReturnTrue();

        $this->assertSame('done', $this->manager->withLock('test', 20, static fn(): string => 'done'));
    }

    #[Test]
    public function a_lock_timeout_raises_lock_acquisition_without_running_the_callback(): void
    {
        $this->cache->shouldReceive('lock')->andReturn($this->lock);
        $this->lock->shouldReceive('block')->andThrow(new LockTimeoutException());
        $this->lock->shouldNotReceive('release');

        $this->assertLockAcquisitionFailure(LockTimeoutException::class);
    }

    #[Test]
    public function a_store_failure_creating_the_lock_raises_lock_acquisition_without_running_the_callback(): void
    {
        $this->cache->shouldReceive('lock')->andThrow(new RuntimeException('Connection refused'));

        $this->assertLockAcquisitionFailure(RuntimeException::class);
    }

    #[Test]
    public function a_store_failure_while_blocking_raises_lock_acquisition_without_running_the_callback(): void
    {
        $this->cache->shouldReceive('lock')->andReturn($this->lock);
        $this->lock->shouldReceive('block')->andThrow(new RuntimeException('Connection refused'));
        $this->lock->shouldNotReceive('release');

        $this->assertLockAcquisitionFailure(RuntimeException::class);
    }

    #[Test]
    public function a_store_failure_on_release_is_logged_and_does_not_throw(): void
    {
        $this->cache->shouldReceive('lock')->andReturn($this->lock);
        $this->lock->shouldReceive('block')->andReturnTrue();
        $this->lock->shouldReceive('release')->andThrow(new RuntimeException('Connection refused'));
        $this->logger->shouldReceive('warning')
            ->once()
            ->with('Lock release failed', Mockery::on(
                static fn(array $context): bool => $context['lock'] === 'test'
                    && $context['exception'] === RuntimeException::class,
            ));

        $this->assertSame('done', $this->manager->withLock('test', 20, static fn(): string => 'done'));
    }

    #[Test]
    public function an_exception_from_the_callback_propagates_untouched_after_release(): void
    {
        $this->cache->shouldReceive('lock')->andReturn($this->lock);
        $this->lock->shouldReceive('block')->andReturnTrue();
        $this->lock->shouldReceive('release')->once()->andReturnTrue();
        $thrown = new DomainException('callback failed');

        try {
            $this->manager->withLock('test', 20, static function () use ($thrown): never {
                throw $thrown;
            });
            $this->fail('Expected the callback exception');
        } catch (DomainException $e) {
            $this->assertSame($thrown, $e);
        }
    }

    /**
     * @param class-string $previous
     */
    private function assertLockAcquisitionFailure(string $previous): void
    {
        $ran = false;

        try {
            $this->manager->withLock('test', 20, static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('Expected LockAcquisitionException');
        } catch (LockAcquisitionException $e) {
            $this->assertSame('test', $e->lockName);
            $this->assertSame(20, $e->timeoutSeconds);
            $this->assertInstanceOf($previous, $e->getPrevious());
        }

        $this->assertFalse($ran);
    }
}
