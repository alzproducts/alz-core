<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\QuickBooks\CacheQuickBooksStateStore;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(CacheQuickBooksStateStore::class)]
final class CacheQuickBooksStateStoreTest extends TestCase
{
    #[Test]
    public function an_issued_state_is_consumed_exactly_once(): void
    {
        $states = $this->app->make(QuickBooksStateStoreInterface::class);

        $state = $states->issue();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $state);
        $this->assertTrue($states->consume($state));
        $this->assertFalse($states->consume($state));
    }

    #[Test]
    public function an_unknown_state_is_not_consumed(): void
    {
        $this->assertFalse($this->app->make(QuickBooksStateStoreInterface::class)->consume(\str_repeat('b', 64)));
    }

    #[Test]
    public function a_state_another_request_already_claimed_is_not_consumed(): void
    {
        $cache = new Repository(new ArrayStore());
        $states = $this->store($cache);
        $state = $states->issue();
        $cache->put("quickbooks:oauth-state:sandbox:{$state}:consumed", true, 600);

        $this->assertFalse($states->consume($state));
    }

    #[Test]
    public function an_unreachable_cache_raises_unavailable(): void
    {
        $cache = Mockery::mock(RepositoryContract::class);
        $cache->shouldReceive('get')->andThrow(new RuntimeException('redis down'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->store($cache)->consume(\str_repeat('c', 64));
    }

    private function store(RepositoryContract $cache): CacheQuickBooksStateStore
    {
        return new CacheQuickBooksStateStore(
            store: $cache,
            config: $this->app->make(QuickBooksConfig::class),
            logger: new NullLogger(),
        );
    }
}
