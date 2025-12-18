<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Support;

use App\Application\Support\GracefulCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * GracefulCache Unit Tests.
 *
 * Tests the graceful degradation caching utility covering:
 * - Cache hit/miss behavior in remember()
 * - Graceful degradation on read/write/delete failures
 * - Proper logging with serviceName context
 * - Various data type handling
 */
#[CoversClass(GracefulCache::class)]
final class GracefulCacheTest extends TestCase
{
    private MockObject&CacheInterface $cacheMock;

    private MockObject&LoggerInterface $loggerMock;

    private GracefulCache $gracefulCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheMock = $this->createMock(CacheInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->gracefulCache = new GracefulCache($this->cacheMock, $this->loggerMock);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Cache Hit Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_returns_cached_value_on_hit_and_does_not_call_callback(): void
    {
        $key = 'test-key';
        $expectedValue = 'cached-data';

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn($expectedValue);

        $callback = static fn() => self::fail('Callback should not be executed on cache hit.');
        $this->cacheMock->expects(self::never())->method('set');
        $this->loggerMock->expects(self::never())->method('warning');

        $result = $this->gracefulCache->remember($key, 3600, $callback);

        self::assertSame($expectedValue, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Cache Miss Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_executes_callback_and_caches_result_on_miss(): void
    {
        $key = 'miss-key';
        $ttl = 120;
        $freshValue = ['data' => 'from-callback'];

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn(null);

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $freshValue, $ttl);

        $this->loggerMock->expects(self::never())->method('warning');

        $result = $this->gracefulCache->remember($key, $ttl, static fn() => $freshValue);

        self::assertSame($freshValue, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Graceful Degradation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_executes_callback_and_returns_value_when_cache_read_fails(): void
    {
        $key = 'read-fail-key';
        $ttl = 3600;
        $freshValue = 'value-on-failure';
        $exception = new RuntimeException('Cache unavailable');

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willThrowException($exception);

        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with('cache cache read failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $freshValue, $ttl);

        $result = $this->gracefulCache->remember($key, $ttl, static fn() => $freshValue);

        self::assertSame($freshValue, $result);
    }

    #[Test]
    public function remember_returns_value_and_logs_when_cache_write_fails(): void
    {
        $key = 'write-fail-key';
        $ttl = 3600;
        $freshValue = 'value-on-write-failure';
        $exception = new RuntimeException('Cannot write to cache');

        $this->cacheMock->expects(self::once())->method('get')->with($key)->willReturn(null);

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $freshValue, $ttl)
            ->willThrowException($exception);

        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with('cache cache write failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

        $result = $this->gracefulCache->remember($key, $ttl, static fn() => $freshValue);

        self::assertSame($freshValue, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | forget() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function forget_calls_delete_on_the_cache(): void
    {
        $key = 'key-to-forget';

        $this->cacheMock->expects(self::once())->method('delete')->with($key);
        $this->loggerMock->expects(self::never())->method('warning');

        $this->gracefulCache->forget($key);
    }

    #[Test]
    public function forget_logs_warning_but_does_not_throw_on_deletion_failure(): void
    {
        $key = 'forget-fail-key';
        $exception = new RuntimeException('Cache deletion failed');

        $this->cacheMock->expects(self::once())
            ->method('delete')
            ->with($key)
            ->willThrowException($exception);

        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with('cache cache invalidation failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

        // No exception should be thrown - test passes if this completes
        $this->gracefulCache->forget($key);
    }

    /*
    |--------------------------------------------------------------------------
    | serviceName Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function custom_service_name_is_used_in_log_messages(): void
    {
        $serviceName = 'shopwired-api';
        $sut = new GracefulCache($this->cacheMock, $this->loggerMock, $serviceName);
        $key = 'some-key';
        $exception = new RuntimeException('Connection lost');

        $this->cacheMock->method('get')->willThrowException($exception);
        $this->cacheMock->method('set');

        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with("{$serviceName} cache read failed", self::anything());

        $sut->remember($key, 60, static fn() => 'fallback');
    }

    /*
    |--------------------------------------------------------------------------
    | Data Type Handling Tests
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function variousDataTypesProvider(): array
    {
        return [
            'string' => ['hello world'],
            'integer' => [12345],
            'float' => [3.14159],
            'boolean_true' => [true],
            'boolean_false' => [false],
            'array' => [['a' => 1, 'b' => 2]],
            'object' => [(object) ['id' => 1, 'name' => 'Test']],
        ];
    }

    #[Test]
    #[DataProvider('variousDataTypesProvider')]
    public function remember_handles_various_data_types(mixed $data): void
    {
        $key = 'data-type-key';

        $this->cacheMock->method('get')->willReturn(null);
        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $data, self::anything());

        $result = $this->gracefulCache->remember($key, 60, static fn() => $data);

        self::assertEquals($data, $result);
    }

    #[Test]
    public function callback_returning_null_is_treated_as_miss_on_next_call(): void
    {
        $key = 'key-for-null';
        $ttl = 60;

        // Both calls will return null (treating stored null as a miss)
        $this->cacheMock->expects(self::exactly(2))
            ->method('get')
            ->with($key)
            ->willReturn(null);

        $this->cacheMock->expects(self::exactly(2))
            ->method('set')
            ->with($key, null, $ttl);

        $callbackExecutionCount = 0;
        $callback = static function () use (&$callbackExecutionCount) {
            $callbackExecutionCount++;

            return null;
        };

        $result1 = $this->gracefulCache->remember($key, $ttl, $callback);
        self::assertNull($result1);
        self::assertSame(1, $callbackExecutionCount, 'Callback should be called on the first attempt.');

        $result2 = $this->gracefulCache->remember($key, $ttl, $callback);
        self::assertNull($result2);
        self::assertSame(2, $callbackExecutionCount, 'Callback should be called again because stored null is treated as a miss.');
    }

    /*
    |--------------------------------------------------------------------------
    | get() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_returns_cached_value(): void
    {
        $key = 'get-test-key';
        $expectedValue = 'cached-value';

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn($expectedValue);

        $this->loggerMock->expects(self::never())->method('warning');

        $result = $this->gracefulCache->get($key);

        self::assertSame($expectedValue, $result);
    }

    #[Test]
    public function get_returns_null_on_cache_miss(): void
    {
        $key = 'miss-key';

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn(null);

        $this->loggerMock->expects(self::never())->method('warning');

        $result = $this->gracefulCache->get($key);

        self::assertNull($result);
    }

    #[Test]
    public function get_returns_null_and_logs_on_cache_read_failure(): void
    {
        $key = 'fail-key';
        $exception = new RuntimeException('Cache read error');

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willThrowException($exception);

        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with('cache cache read failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

        $result = $this->gracefulCache->get($key);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | put() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function put_stores_value_in_cache(): void
    {
        $key = 'put-key';
        $value = 'put-value';
        $ttl = 300;

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $value, $ttl);

        $this->loggerMock->expects(self::never())->method('warning');

        $this->gracefulCache->put($key, $value, $ttl);
    }

    #[Test]
    public function put_logs_warning_but_does_not_throw_on_cache_write_failure(): void
    {
        $key = 'put-fail-key';
        $value = 'some-value';
        $ttl = 600;
        $exception = new RuntimeException('Cache write error');

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $value, $ttl)
            ->willThrowException($exception);

        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with('cache cache write failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

        // Should not throw - test passes if completes
        $this->gracefulCache->put($key, $value, $ttl);
    }

    /*
    |--------------------------------------------------------------------------
    | rememberInt() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_int_returns_cached_integer(): void
    {
        $key = 'int-key';

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn(42);

        $this->loggerMock->expects(self::never())->method('warning');

        $result = $this->gracefulCache->rememberInt($key, 60, static fn() => 99);

        self::assertSame(42, $result);
        self::assertIsInt($result);
    }

    #[Test]
    public function remember_int_casts_string_to_integer(): void
    {
        // Redis serializes integers as strings
        $key = 'string-int-key';

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn('123'); // String from Redis

        $result = $this->gracefulCache->rememberInt($key, 60, static fn() => 999);

        self::assertSame(123, $result);
        self::assertIsInt($result);
    }

    #[Test]
    public function remember_int_returns_null_when_callback_returns_null(): void
    {
        $key = 'null-int-key';

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn(null);

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, null, 60);

        $result = $this->gracefulCache->rememberInt($key, 60, static fn() => null);

        self::assertNull($result);
    }

    #[Test]
    public function remember_int_executes_callback_on_cache_miss(): void
    {
        $key = 'miss-int-key';
        $expectedValue = 777;

        $this->cacheMock->expects(self::once())
            ->method('get')
            ->with($key)
            ->willReturn(null);

        $this->cacheMock->expects(self::once())
            ->method('set')
            ->with($key, $expectedValue, 120);

        $result = $this->gracefulCache->rememberInt($key, 120, static fn() => $expectedValue);

        self::assertSame($expectedValue, $result);
        self::assertIsInt($result);
    }
}
