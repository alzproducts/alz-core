<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderTotalsQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\Dispatchers\QueuedOrderSyncDispatcher;
use App\Infrastructure\QuickBooks\EloquentOrderSyncSourceQueryRepository;
use App\Infrastructure\QuickBooks\EloquentOrderTotalsQueryRepository;
use App\Infrastructure\QuickBooks\EloquentQuickBooksMappingRepository;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksMappingCandidateReader;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use App\Providers\QuickBooksServiceProvider;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class QuickBooksServiceProviderTest extends TestCase
{
    #[Test]
    public function the_deferred_provider_resolves_order_posting(): void
    {
        $this->assertContains(QuickBooksOrderPostingInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(QuickBooksClient::class, $this->app->make(QuickBooksOrderPostingInterface::class));
    }

    #[Test]
    public function the_deferred_provider_resolves_the_mapping_repository(): void
    {
        $this->assertContains(QuickBooksMappingRepositoryInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(EloquentQuickBooksMappingRepository::class, $this->app->make(QuickBooksMappingRepositoryInterface::class));
    }

    #[Test]
    public function the_deferred_provider_resolves_the_source_orders(): void
    {
        $this->assertContains(OrderSyncSourceQueryRepositoryInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(EloquentOrderSyncSourceQueryRepository::class, $this->app->make(OrderSyncSourceQueryRepositoryInterface::class));
    }

    #[Test]
    public function the_deferred_provider_resolves_the_order_totals(): void
    {
        $this->assertContains(OrderTotalsQueryRepositoryInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(EloquentOrderTotalsQueryRepository::class, $this->app->make(OrderTotalsQueryRepositoryInterface::class));
    }

    #[Test]
    public function the_deferred_provider_resolves_the_mapping_candidates(): void
    {
        $this->assertContains(QuickBooksMappingCandidatesInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(QuickBooksMappingCandidateReader::class, $this->app->make(QuickBooksMappingCandidatesInterface::class));
    }

    #[Test]
    public function the_realm_is_the_one_bound_config(): void
    {
        $config = new QuickBooksConfig(QuickBooksEnvironment::Production, 'id', 'secret', 'https://example.test/callback', '9130');
        $this->app->instance(QuickBooksConfig::class, $config);

        $this->assertContains(QuickBooksRealmInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertSame($config, $this->app->make(QuickBooksRealmInterface::class));
        $this->assertSame('9130', $config->realmId());
    }

    #[Test]
    public function the_deferred_provider_resolves_the_order_sync_queue(): void
    {
        $provides = new QuickBooksServiceProvider($this->app)->provides();
        foreach ([OrderSyncDispatcherInterface::class, QueuedOrderSyncDispatcher::class, QuickBooksOrderChunkSlot::class] as $abstract) {
            $this->assertContains($abstract, $provides);
        }
        $this->assertInstanceOf(QueuedOrderSyncDispatcher::class, $this->app->make(OrderSyncDispatcherInterface::class));
        $this->assertInstanceOf(QuickBooksOrderChunkSlot::class, $this->app->make(QuickBooksOrderChunkSlot::class));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConcurrencies(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'blank' => [''];
        yield 'not a whole number' => ['1.5'];
        yield 'not a number' => ['many'];
    }

    #[Test]
    public function one_chunk_runs_at_a_time_unless_configured(): void
    {
        Env::getRepository()->clear('QUICKBOOKS_ORDER_SYNC_CONCURRENCY');

        $config = require \config_path('quickbooks.php');

        $this->assertIsArray($config);
        $this->assertSame(1, $config['order_sync_concurrency'] ?? null);
    }

    #[Test]
    #[DataProvider('invalidConcurrencies')]
    public function a_concurrency_no_chunk_could_run_at_is_refused(mixed $concurrency): void
    {
        \config(['quickbooks.order_sync_concurrency' => $concurrency]);

        $this->expectException(InvalidConfigurationException::class);

        $this->app->make(OrderSyncDispatcherInterface::class);
    }
}
