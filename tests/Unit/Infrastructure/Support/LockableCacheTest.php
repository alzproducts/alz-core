<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Support;

use App\Infrastructure\Support\LockableCache;
use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * LockableCache Unit Tests.
 *
 * Tests the atomic locking cache implementation including:
 * - Basic cache operations (get, put, forget)
 * - Lock acquisition and double-check pattern
 * - Graceful degradation on infrastructure failures
 * - Stale value fallback on factory failures
 * - Custom validator support
 */
#[CoversClass(LockableCache::class)]
final class LockableCacheTest extends TestCase
{
    private CacheManager&MockInterface $mockCache;
    private LoggerInterface&MockInterface $mockLogger;
    private LockableCache $lockableCache;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockCache = Mockery::mock(CacheManager::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('warning')->byDefault();

        $this->lockableCache = new LockableCache(
            $this->mockCache,
            $this->mockLogger,
            'test-service',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | forget() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function forget_deletes_from_cache(): void
    {
        $this->mockCache->shouldReceive('forget')
            ->with('test-key')
            ->once();

        $this->lockableCache->forget('test-key');

        // Assertion via mock expectation
        $this->assertTrue(true);
    }

    #[Test]
    public function forget_logs_and_continues_on_cache_failure(): void
    {
        $exception = new RuntimeException('Redis connection failed');

        $this->mockCache->shouldReceive('forget')
            ->with('test-key')
            ->andThrow($exception);

        $this->mockLogger->shouldReceive('warning')
            ->with('test-service delete failed', Mockery::on(static fn(array $context) => $context['operation'] === 'delete'
                    && $context['key'] === 'test-key'
                    && $context['exception'] === RuntimeException::class))
            ->once();

        // Should not throw - graceful degradation
        $this->lockableCache->forget('test-key');
        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Cache Hit
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_returns_cached_value_on_hit(): void
    {
        $cachedValue = ['data' => 'cached'];

        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn($cachedValue);

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => ['data' => 'fresh'],
            3600,
        );

        $this->assertSame($cachedValue, $result);
    }

    #[Test]
    public function remember_does_not_call_factory_on_cache_hit(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn('cached');

        $factoryCalled = false;
        $this->lockableCache->remember(
            'test-key',
            static function () use (&$factoryCalled) {
                $factoryCalled = true;

                return 'fresh';
            },
            3600,
        );

        $this->assertFalse($factoryCalled);
    }

    #[Test]
    public function remember_uses_validator_for_cache_hit(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(['status' => 'invalid']);

        $this->setupSuccessfulLockWithCacheMiss();

        $this->mockCache->shouldReceive('put')
            ->with('test-key', ['status' => 'valid'], 3600)
            ->once();

        $validator = static fn(array $value): bool => $value['status'] === 'valid';

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => ['status' => 'valid'],
            3600,
            $validator,
        );

        $this->assertSame(['status' => 'valid'], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Cache Miss with Lock
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_acquires_lock_and_calls_factory_on_miss(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null, null); // Initial miss, miss after lock

        $this->setupSuccessfulLock();

        $this->mockCache->shouldReceive('put')
            ->with('test-key', 'fresh-value', 3600)
            ->once();

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => 'fresh-value',
            3600,
        );

        $this->assertSame('fresh-value', $result);
    }

    #[Test]
    public function remember_uses_double_check_after_lock_acquisition(): void
    {
        // First get: cache miss
        // Second get (double-check after lock): cache hit
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null, 'another-process-value');

        $this->setupSuccessfulLock();

        // put() should NOT be called since double-check found value
        $this->mockCache->shouldNotReceive('put');

        $factoryCalled = false;
        $result = $this->lockableCache->remember(
            'test-key',
            static function () use (&$factoryCalled) {
                $factoryCalled = true;

                return 'my-value';
            },
            3600,
        );

        $this->assertSame('another-process-value', $result);
        $this->assertFalse($factoryCalled);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Lock Timeout/Failure
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_logs_and_continues_without_lock_on_timeout(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null);

        $mockLock = Mockery::mock(Lock::class);
        $mockLock->shouldReceive('block')
            ->with(10) // LOCK_WAIT_SECONDS
            ->andReturn(false);

        $this->mockCache->shouldReceive('lock')
            ->with('test-key:lock', 30) // LOCK_TIMEOUT_SECONDS
            ->andReturn($mockLock);

        $this->mockLogger->shouldReceive('warning')
            ->with('test-service lock timeout', Mockery::on(static fn(array $context) => $context['key'] === 'test-key'
                    && $context['wait_seconds'] === 10))
            ->once();

        // Fallback path without lock
        $this->mockCache->shouldReceive('put')
            ->with('test-key', 'fallback-value', 3600)
            ->once();

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => 'fallback-value',
            3600,
        );

        $this->assertSame('fallback-value', $result);
    }

    #[Test]
    public function remember_logs_and_continues_without_lock_on_exception(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null);

        $lockException = new RuntimeException('Redis lock unavailable');
        $this->mockCache->shouldReceive('lock')
            ->andThrow($lockException);

        $this->mockLogger->shouldReceive('warning')
            ->with('test-service lock failed', Mockery::on(static fn(array $context) => $context['operation'] === 'lock'
                    && $context['key'] === 'test-key'
                    && $context['exception'] === RuntimeException::class))
            ->once();

        // Fallback without lock
        $this->mockCache->shouldReceive('put')
            ->with('test-key', 'emergency-value', 3600)
            ->once();

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => 'emergency-value',
            3600,
        );

        $this->assertSame('emergency-value', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Infrastructure Failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_continues_on_cache_read_failure(): void
    {
        $this->mockCache->shouldReceive('get')
            ->andThrow(new RuntimeException('Redis unavailable'));

        $this->mockCache->shouldReceive('lock')
            ->andThrow(new RuntimeException('Lock unavailable'));

        $this->mockCache->shouldReceive('put')
            ->with('test-key', 'fresh', 3600)
            ->once();

        $this->mockLogger->shouldReceive('warning')->times(2); // read failure, lock failure

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => 'fresh',
            3600,
        );

        $this->assertSame('fresh', $result);
    }

    #[Test]
    public function remember_continues_on_cache_write_failure(): void
    {
        $this->mockCache->shouldReceive('get')
            ->andReturn(null);

        $this->setupSuccessfulLockWithCacheMiss();

        $this->mockCache->shouldReceive('put')
            ->andThrow(new RuntimeException('Write failed'));

        $this->mockLogger->shouldReceive('warning')
            ->with('test-service write failed', Mockery::type('array'))
            ->once();

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => 'value-despite-write-failure',
            3600,
        );

        $this->assertSame('value-despite-write-failure', $result);
    }

    #[Test]
    public function remember_propagates_factory_exception(): void
    {
        $this->mockCache->shouldReceive('get')
            ->andReturn(null);

        $this->setupSuccessfulLockWithCacheMiss();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Factory exploded');

        $this->lockableCache->remember(
            'test-key',
            static fn() => throw new RuntimeException('Factory exploded'),
            3600,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | rememberOrStale() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_or_stale_returns_cached_value_on_hit(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn('cached');

        $result = $this->lockableCache->rememberOrStale(
            'test-key',
            static fn() => 'fresh',
            3600,
        );

        $this->assertSame('cached', $result);
    }

    #[Test]
    public function remember_or_stale_returns_stale_value_on_factory_failure(): void
    {
        // First get: returns stale value (invalid per validator)
        // After lock fails/factory fails, we should get stale back
        $staleValue = ['status' => 'stale', 'data' => 'old'];

        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn($staleValue);

        $this->setupSuccessfulLockWithCacheMiss();

        $this->mockLogger->shouldReceive('warning')
            ->with('test-service factory failed, returning stale value', Mockery::type('array'))
            ->once();

        $validator = static fn(array $value): bool => $value['status'] === 'fresh';

        $result = $this->lockableCache->rememberOrStale(
            'test-key',
            static fn() => throw new Exception('API is down'),
            3600,
            $validator,
        );

        $this->assertSame($staleValue, $result);
    }

    #[Test]
    public function remember_or_stale_throws_when_no_stale_value_available(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null, null);

        $this->setupSuccessfulLock();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No fallback available');

        $this->lockableCache->rememberOrStale(
            'test-key',
            static fn() => throw new Exception('No fallback available'),
            3600,
        );
    }

    #[Test]
    public function remember_or_stale_returns_stale_when_lock_and_factory_fail(): void
    {
        $staleValue = 'stale-data';

        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn($staleValue);

        // Lock fails
        $this->mockCache->shouldReceive('lock')
            ->andThrow(new RuntimeException('Lock broken'));

        $this->mockLogger->shouldReceive('warning')
            ->with('test-service lock failed', Mockery::type('array'));

        // Factory called without lock, also fails
        $this->mockLogger->shouldReceive('warning')
            ->with('test-service factory failed, returning stale value', Mockery::type('array'))
            ->once();

        // Use validator to force factory call
        $validator = static fn($value): bool => $value === 'fresh';

        $result = $this->lockableCache->rememberOrStale(
            'test-key',
            static fn() => throw new Exception('Total failure'),
            3600,
            $validator,
        );

        $this->assertSame($staleValue, $result);
    }

    #[Test]
    public function remember_or_stale_refreshes_successfully_on_miss(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null, null);

        $this->setupSuccessfulLock();

        $this->mockCache->shouldReceive('put')
            ->with('test-key', 'fresh-data', 3600)
            ->once();

        $result = $this->lockableCache->rememberOrStale(
            'test-key',
            static fn() => 'fresh-data',
            3600,
        );

        $this->assertSame('fresh-data', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | isValid() Tests (via remember)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function validator_returning_false_triggers_refresh(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(['expired' => true]);

        $this->setupSuccessfulLockWithCacheMiss();

        $this->mockCache->shouldReceive('put')
            ->once();

        $validator = static fn(array $value): bool => !isset($value['expired']);

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => ['fresh' => true],
            3600,
            $validator,
        );

        $this->assertSame(['fresh' => true], $result);
    }

    #[Test]
    public function null_cached_value_always_triggers_refresh(): void
    {
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null);

        $this->setupSuccessfulLockWithCacheMiss();

        $this->mockCache->shouldReceive('put')
            ->once();

        // Even with a permissive validator, null should trigger refresh
        $validator = static fn(): bool => true;

        $result = $this->lockableCache->remember(
            'test-key',
            static fn() => 'new-value',
            3600,
            $validator,
        );

        $this->assertSame('new-value', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Service Name in Logs
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function uses_service_name_in_log_messages(): void
    {
        $customCache = new LockableCache(
            $this->mockCache,
            $this->mockLogger,
            'my-custom-service',
        );

        $this->mockCache->shouldReceive('forget')
            ->andThrow(new RuntimeException('Error'));

        $this->mockLogger->shouldReceive('warning')
            ->with('my-custom-service delete failed', Mockery::type('array'))
            ->once();

        $customCache->forget('any-key');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function setupSuccessfulLock(): void
    {
        $mockLock = Mockery::mock(Lock::class);
        $mockLock->shouldReceive('block')
            ->with(10)
            ->andReturn(true);
        $mockLock->shouldReceive('release')
            ->once();

        $this->mockCache->shouldReceive('lock')
            ->with('test-key:lock', 30)
            ->andReturn($mockLock);
    }

    private function setupSuccessfulLockWithCacheMiss(): void
    {
        $this->setupSuccessfulLock();

        // Double-check returns null (cache still empty)
        $this->mockCache->shouldReceive('get')
            ->with('test-key')
            ->andReturn(null);
    }
}
