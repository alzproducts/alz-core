<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Support;

use App\Infrastructure\Support\ResilientCache;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(ResilientCache::class)]
final class ResilientCacheTest extends TestCase
{
    private CacheManager&MockInterface $mockCache;
    private LoggerInterface&MockInterface $mockLogger;
    private Repository&MockInterface $mockStore;
    private ResilientCache $resilientCache;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockCache = Mockery::mock(CacheManager::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('warning')->byDefault();
        $this->mockStore = Mockery::mock(Repository::class);

        $this->mockCache->shouldReceive('store')
            ->withNoArgs()
            ->andReturn($this->mockStore)
            ->byDefault();

        $this->resilientCache = new ResilientCache(
            $this->mockCache,
            $this->mockLogger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Cache Hit
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_returns_cached_value_on_hit(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('test-key')
            ->once()
            ->andReturn('cached-data');

        $this->mockStore->shouldNotReceive('put');

        $result = $this->resilientCache->remember('test-key', 3600, static fn() => self::fail('Callback should not execute'));

        $this->assertSame('cached-data', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Cache Miss
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_executes_callback_and_caches_on_miss(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('miss-key')
            ->once()
            ->andReturnNull();

        $this->mockStore->shouldReceive('put')
            ->with('miss-key', ['data' => 'fresh'], 120)
            ->once();

        $result = $this->resilientCache->remember('miss-key', 120, static fn() => ['data' => 'fresh']);

        $this->assertSame(['data' => 'fresh'], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | remember() - Graceful Degradation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_executes_callback_when_cache_read_fails(): void
    {
        $exception = new RuntimeException('Cache unavailable');

        $this->mockStore->shouldReceive('get')
            ->with('read-fail-key')
            ->once()
            ->andThrow($exception);

        $this->mockLogger->shouldReceive('warning')
            ->with('cache read failed', Mockery::on(static fn(array $ctx) => $ctx['operation'] === 'read'
                && $ctx['key'] === 'read-fail-key'
                && $ctx['exception'] === RuntimeException::class))
            ->once();

        $this->mockStore->shouldReceive('put')
            ->with('read-fail-key', 'fallback', 3600)
            ->once();

        $result = $this->resilientCache->remember('read-fail-key', 3600, static fn() => 'fallback');

        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function remember_returns_value_when_cache_write_fails(): void
    {
        $exception = new RuntimeException('Cannot write');

        $this->mockStore->shouldReceive('get')
            ->with('write-fail')
            ->andReturnNull();

        $this->mockStore->shouldReceive('put')
            ->with('write-fail', 'fresh', 3600)
            ->andThrow($exception);

        $this->mockLogger->shouldReceive('warning')
            ->with('cache write failed', Mockery::on(static fn(array $ctx) => $ctx['operation'] === 'write'
                && $ctx['key'] === 'write-fail'
                && $ctx['exception'] === RuntimeException::class))
            ->once();

        $result = $this->resilientCache->remember('write-fail', 3600, static fn() => 'fresh');

        $this->assertSame('fresh', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | get() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_returns_cached_value(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('get-key')
            ->once()
            ->andReturn('value');

        $this->assertSame('value', $this->resilientCache->get('get-key'));
    }

    #[Test]
    public function get_returns_null_on_miss(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('miss')
            ->once()
            ->andReturnNull();

        $this->assertNull($this->resilientCache->get('miss'));
    }

    #[Test]
    public function get_returns_null_and_logs_on_failure(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('fail-key')
            ->andThrow(new RuntimeException('Redis down'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache read failed', Mockery::on(static fn(array $ctx) => $ctx['key'] === 'fail-key'))
            ->once();

        $this->assertNull($this->resilientCache->get('fail-key'));
    }

    /*
    |--------------------------------------------------------------------------
    | put() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function put_stores_value(): void
    {
        $this->mockStore->shouldReceive('put')
            ->with('put-key', 'value', 300)
            ->once();

        $this->resilientCache->put('put-key', 'value', 300);
    }

    #[Test]
    public function put_logs_and_continues_on_failure(): void
    {
        $this->mockStore->shouldReceive('put')
            ->andThrow(new RuntimeException('Write failed'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache write failed', Mockery::on(static fn(array $ctx) => $ctx['key'] === 'put-key'))
            ->once();

        $this->resilientCache->put('put-key', 'value', 300);
    }

    /*
    |--------------------------------------------------------------------------
    | forget() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function forget_deletes_from_cache(): void
    {
        $this->mockStore->shouldReceive('forget')
            ->with('del-key')
            ->once();

        $this->resilientCache->forget('del-key');
    }

    #[Test]
    public function forget_logs_and_continues_on_failure(): void
    {
        $this->mockStore->shouldReceive('forget')
            ->with('del-key')
            ->andThrow(new RuntimeException('Delete failed'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache delete failed', Mockery::on(static fn(array $ctx) => $ctx['operation'] === 'delete'
                && $ctx['key'] === 'del-key'))
            ->once();

        $this->resilientCache->forget('del-key');
    }

    /*
    |--------------------------------------------------------------------------
    | Tag Support — Untagged (null tag)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function untagged_operations_use_default_store(): void
    {
        $this->mockCache->shouldReceive('store')
            ->withNoArgs()
            ->once()
            ->andReturn($this->mockStore);

        $this->mockCache->shouldNotReceive('tags');

        $this->mockStore->shouldReceive('get')
            ->with('key')
            ->andReturn('val');

        $this->assertSame('val', $this->resilientCache->get('key'));
    }

    /*
    |--------------------------------------------------------------------------
    | Tag Support — Tagged
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function tagged_get_routes_through_tags(): void
    {
        $taggedStore = Mockery::mock(TaggedCache::class);

        $this->mockCache->shouldReceive('tags')
            ->with(['products'])
            ->once()
            ->andReturn($taggedStore);

        $taggedStore->shouldReceive('get')
            ->with('tagged-key')
            ->andReturn('tagged-value');

        $this->assertSame('tagged-value', $this->resilientCache->get('tagged-key', 'products'));
    }

    #[Test]
    public function tagged_put_routes_through_tags(): void
    {
        $taggedStore = Mockery::mock(TaggedCache::class);

        $this->mockCache->shouldReceive('tags')
            ->with(['orders'])
            ->once()
            ->andReturn($taggedStore);

        $taggedStore->shouldReceive('put')
            ->with('order-1', 'data', 600)
            ->once();

        $this->resilientCache->put('order-1', 'data', 600, 'orders');
    }

    #[Test]
    public function tagged_forget_routes_through_tags(): void
    {
        $taggedStore = Mockery::mock(TaggedCache::class);

        $this->mockCache->shouldReceive('tags')
            ->with(['items'])
            ->once()
            ->andReturn($taggedStore);

        $taggedStore->shouldReceive('forget')
            ->with('item-key')
            ->once();

        $this->resilientCache->forget('item-key', 'items');
    }

    #[Test]
    public function tagged_remember_routes_through_tags(): void
    {
        $taggedStore = Mockery::mock(TaggedCache::class);

        $this->mockCache->shouldReceive('tags')
            ->with(['helpscout'])
            ->andReturn($taggedStore);

        $taggedStore->shouldReceive('get')
            ->with('hs-key')
            ->andReturnNull();

        $taggedStore->shouldReceive('put')
            ->with('hs-key', 'fresh', 60)
            ->once();

        $result = $this->resilientCache->remember('hs-key', 60, static fn() => 'fresh', 'helpscout');

        $this->assertSame('fresh', $result);
    }

    #[Test]
    public function tagged_operation_includes_tag_in_log_context(): void
    {
        $taggedStore = Mockery::mock(TaggedCache::class);

        $this->mockCache->shouldReceive('tags')
            ->with(['orders'])
            ->andReturn($taggedStore);

        $taggedStore->shouldReceive('get')
            ->andThrow(new RuntimeException('Tagged read fail'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache read failed', Mockery::on(static fn(array $ctx) => $ctx['key'] === 'order-key'
                && $ctx['tag'] === 'orders'))
            ->once();

        $this->assertNull($this->resilientCache->get('order-key', 'orders'));
    }

    #[Test]
    public function untagged_operation_omits_tag_from_log_context(): void
    {
        $this->mockStore->shouldReceive('get')
            ->andThrow(new RuntimeException('Read fail'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache read failed', Mockery::on(static fn(array $ctx) => ! \array_key_exists('tag', $ctx)))
            ->once();

        $this->assertNull($this->resilientCache->get('no-tag-key'));
    }

    /*
    |--------------------------------------------------------------------------
    | Tag Support — Graceful degradation on non-taggable backends
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function tagged_operation_degrades_gracefully_on_non_taggable_backend(): void
    {
        $this->mockCache->shouldReceive('tags')
            ->with(['products'])
            ->andThrow(new RuntimeException('This cache store does not support tagging'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache read failed', Mockery::type('array'))
            ->once();

        $this->assertNull($this->resilientCache->get('key', 'products'));
    }

    /*
    |--------------------------------------------------------------------------
    | flushTag() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function flush_tag_flushes_tagged_store(): void
    {
        $taggedStore = Mockery::mock(TaggedCache::class);

        $this->mockCache->shouldReceive('tags')
            ->with(['helpscout'])
            ->once()
            ->andReturn($taggedStore);

        $taggedStore->shouldReceive('flush')
            ->once();

        $this->resilientCache->flushTag('helpscout');
    }

    #[Test]
    public function flush_tag_logs_and_continues_on_failure(): void
    {
        $this->mockCache->shouldReceive('tags')
            ->with(['bad-tag'])
            ->andThrow(new RuntimeException('Tagging not supported'));

        $this->mockLogger->shouldReceive('warning')
            ->with('cache flush failed', Mockery::on(static fn(array $ctx) => $ctx['key'] === 'bad-tag'
                && $ctx['tag'] === 'bad-tag'))
            ->once();

        $this->resilientCache->flushTag('bad-tag');
    }

    /*
    |--------------------------------------------------------------------------
    | rememberInt() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remember_int_returns_cached_integer(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('int-key')
            ->andReturn(42);

        $result = $this->resilientCache->rememberInt('int-key', 60, static fn() => 99);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function remember_int_casts_string_to_integer(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('str-int')
            ->andReturn('123');

        $result = $this->resilientCache->rememberInt('str-int', 60, static fn() => 999);

        $this->assertSame(123, $result);
    }

    #[Test]
    public function remember_int_returns_null_when_callback_returns_null(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('null-int')
            ->andReturnNull();

        $this->mockStore->shouldReceive('put')
            ->with('null-int', null, 60)
            ->once();

        $result = $this->resilientCache->rememberInt('null-int', 60, static fn() => null);

        $this->assertNull($result);
    }

    #[Test]
    public function remember_int_executes_callback_on_miss(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('miss-int')
            ->andReturnNull();

        $this->mockStore->shouldReceive('put')
            ->with('miss-int', 777, 120)
            ->once();

        $result = $this->resilientCache->rememberInt('miss-int', 120, static fn() => 777);

        $this->assertSame(777, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Data Type Handling
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
        $this->mockStore->shouldReceive('get')
            ->andReturnNull();

        $this->mockStore->shouldReceive('put')
            ->with('data-key', $data, Mockery::any())
            ->once();

        $result = $this->resilientCache->remember('data-key', 60, static fn() => $data);

        $this->assertEquals($data, $result);
    }

    #[Test]
    public function null_callback_result_treated_as_miss_on_next_call(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('null-key')
            ->andReturnNull();

        $this->mockStore->shouldReceive('put')
            ->with('null-key', null, 60);

        $callCount = 0;
        $callback = static function () use (&$callCount) {
            $callCount++;

            return null;
        };

        $this->resilientCache->remember('null-key', 60, $callback);
        $this->assertSame(1, $callCount);

        $this->resilientCache->remember('null-key', 60, $callback);
        $this->assertSame(2, $callCount);
    }
}
