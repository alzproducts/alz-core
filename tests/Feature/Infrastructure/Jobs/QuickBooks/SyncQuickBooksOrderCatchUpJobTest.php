<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Results\CatchUpOrderSyncResult;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\UseCases\RunCatchUpOrderSyncUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderCatchUpJob;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\Dispatchers\QueuedOrderSyncDispatcher;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\FakeSlotRedis;
use Tests\TestCase;

/**
 * The hourly catch-up job over the candidate fixture, through the real use case and dispatcher.
 */
#[CoversClass(SyncQuickBooksOrderCatchUpJob::class)]
#[CoversClass(RunCatchUpOrderSyncUseCase::class)]
final class SyncQuickBooksOrderCatchUpJobTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;
    use RunsQueuedJobs;

    private const string REALM = '9130000000000001';

    private const string HANDLE_AT = '2031-07-15T12:00:00+00:00';

    private const string RECENT = '2031-07-15 10:00:00+00';

    /** @var list<int> the fixture's candidates, by reference */
    private const array CANDIDATES = [890000001, 890000002, 890000003];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logs = [];

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(new CarbonImmutable(self::HANDLE_AT));
        \config(['quickbooks.backfill_floor_date' => '2031-07-01', 'quickbooks.order_sync_schedule_from' => '2031-07-11']);
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.order_sync_runs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        DB::table('shopwired.orders')
            ->whereBetween('order_placed_at', ['2031-06-01 00:00:00+00', '2031-08-01 00:00:00+00'])
            ->update(['order_placed_at' => '1990-01-01 00:00:00+00']);
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting = new FakeQuickBooksOrderPosting());
        $this->app->instance(QuickBooksOrderChunkSlot::class, new QuickBooksOrderChunkSlot(new FakeSlotRedis(), new NullLogger(), 1));
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logs[] = [$log->level, $log->message, $log->context];
        });
    }

    #[Test]
    public function with_the_switch_unset_the_job_returns_without_resolving_the_use_case(): void
    {
        $this->connect();
        $this->insertFixture();
        \config(['quickbooks.order_sync_schedule_from' => null, 'quickbooks.client_id' => '']);
        $this->app->forgetInstance(QuickBooksConfig::class);

        $this->handle();

        self::assertFalse($this->app->resolved(RunCatchUpOrderSyncUseCase::class));
        $this->assertNothingRun('info', 'Skipped QuickBooks order catch-up sync', CatchUpOrderSyncResult::SKIPPED_SWITCHED_OFF);
    }

    #[Test]
    public function the_fixture_candidates_are_queued_as_unforced_schedule_chunks(): void
    {
        $this->connect();
        $this->insertFixture();

        $this->handle();

        $chunks = $this->chunkJobs();
        self::assertCount(1, $chunks);
        self::assertSame(self::CANDIDATES, $chunks[0]->run->chunk->references);
        self::assertSame([OrderSyncTrigger::Schedule, false, self::REALM, 1], [$chunks[0]->run->chunk->trigger, $chunks[0]->run->chunk->forceRetry, $chunks[0]->run->chunk->realmId, $chunks[0]->run->attempt]);
        self::assertSame($this->runRow()->id, $chunks[0]->run->chunk->runId);
    }

    #[Test]
    public function an_order_placed_90_minutes_before_a_handle_2_hours_after_construction_is_a_candidate(): void
    {
        $this->insertOrder(890000001, '2031-07-15 12:30:00+00');
        $job = new SyncQuickBooksOrderCatchUpJob();
        $this->travelTo(new CarbonImmutable('2031-07-15T14:00:00+00:00'));
        $this->connect();

        $this->work($job);

        self::assertSame([890000001], $this->chunkJobs()[0]->run->chunk->references);
    }

    #[Test]
    public function an_order_failing_in_a_schedule_chunk_records_its_error_with_trigger_schedule(): void
    {
        $this->connect();
        $this->insertOrder(890000001, self::RECENT, sku: 'SKU-MISSING');
        $this->posting->missingSkus = ['SKU-MISSING'];

        $this->handle();
        self::assertFalse($this->work($this->chunkJobs()[0])->hasFailed());

        $runId = $this->runRow()->id;
        self::assertSame(
            [['trigger' => 'schedule', 'run_id' => $runId]],
            DB::table('quickbooks.order_sync_errors')->get(['trigger', 'run_id'])->map(static fn(object $row): array => (array) $row)->all(),
        );
    }

    #[Test]
    public function a_schedule_chunk_posts_the_failed_item_missing_candidate_once_its_item_exists(): void
    {
        $this->connect();
        $this->insertFixture();

        $this->handle();
        self::assertFalse($this->work($this->chunkJobs()[0])->hasFailed());

        $row = DB::table('quickbooks.order_syncs')->where('realm_id', self::REALM)->where('reference', 890000003)->sole();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('synced', $row->status);
        self::assertNotNull($row->qbo_txn_id);
        self::assertContains($row->qbo_doc_number, \array_map(static fn(PostableDocumentDTO $document): string => $document->draft->core->docNumber, $this->posting->documents));
    }

    #[Test]
    public function the_run_row_is_first_written_queued_with_trigger_schedule_and_the_candidate_count(): void
    {
        $this->connect();
        $this->insertFixture();
        $seen = [];
        $this->dispatchThrough(function (QueuedOrderSyncDispatcher $real, OrderChunkRunDTO $run) use (&$seen): void {
            $row = $this->runRow();
            $seen[] = [$row->trigger, $row->status, $row->candidate_count, $row->started_at];
            $real->dispatchChunk($run);
        });

        $this->handle();

        self::assertSame([['schedule', 'queued', \count(self::CANDIDATES), null]], $seen);
    }

    #[Test]
    public function a_two_chunk_run_stays_open_until_its_second_chunk_ends_then_completes(): void
    {
        $this->connect();
        $references = \range(890000101, 890000100 + OrderSyncChunkDTO::MAX_REFERENCES + 1);
        foreach ($references as $reference) {
            $this->insertOrder($reference, self::RECENT);
        }
        $this->chunkSyncsEverything();

        $this->handle();
        $chunks = $this->chunkJobs();
        self::assertCount(2, $chunks);
        self::assertSame($references, [...$chunks[0]->run->chunk->references, ...$chunks[1]->run->chunk->references]);

        $this->work($chunks[0]);
        self::assertSame(['running', false, 1], $this->runState());

        $this->work($chunks[1]);
        self::assertSame(['completed', true, 0], $this->runState());
        self::assertSame(\count($references), $this->runRow()->synced);
    }

    #[Test]
    public function a_throwing_second_chunk_dispatch_ends_the_run_failed_once_the_first_chunk_ends(): void
    {
        $this->connect();
        foreach (\range(890000101, 890000100 + OrderSyncChunkDTO::MAX_REFERENCES + 1) as $reference) {
            $this->insertOrder($reference, self::RECENT);
        }
        $this->chunkSyncsEverything();
        $dispatches = 0;
        $this->dispatchThrough(static function (QueuedOrderSyncDispatcher $real, OrderChunkRunDTO $run) use (&$dispatches): void {
            if (++$dispatches === 2) {
                throw new RuntimeException('test');
            }
            $real->dispatchChunk($run);
        });

        self::assertTrue($this->handle()->hasFailed());
        self::assertSame(['running', false, 1], $this->runState());

        $this->work($this->chunkJobs()[0]);

        self::assertSame(['failed', true, 0], $this->runState());
    }

    #[Test]
    public function a_run_with_no_candidates_queues_nothing(): void
    {
        $this->connect();
        $this->insertOrder(890000001, '2031-07-15 11:30:00+00');

        $this->handle();

        $this->assertNothingRun('info', 'Skipped QuickBooks order catch-up sync', CatchUpOrderSyncResult::SKIPPED_NO_CANDIDATES);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function openScheduleRuns(): iterable
    {
        yield 'queued' => ['queued', '2031-07-15T11:00:00+00:00'];
        yield 'running, just under a day old' => ['running', '2031-07-14T12:00:01+00:00'];
    }

    #[Test]
    #[DataProvider('openScheduleRuns')]
    public function an_open_schedule_run_from_the_last_day_skips_the_run(string $status, string $requestedAt): void
    {
        $this->connect();
        $this->insertFixture();
        $runId = $this->recordRun(OrderSyncTrigger::Schedule, $status, $requestedAt);

        $this->handle();

        self::assertSame([], $this->chunkJobs());
        self::assertSame([$runId], DB::table('quickbooks.order_sync_runs')->pluck('id')->all());
        $this->assertLogged('info', 'Skipped QuickBooks order catch-up sync', CatchUpOrderSyncResult::SKIPPED_PREVIOUS_RUN_OPEN);
    }

    /**
     * @return iterable<string, array{OrderSyncTrigger, string}>
     */
    public static function nonBlockingRuns(): iterable
    {
        yield 'a schedule run over a day old' => [OrderSyncTrigger::Schedule, '2031-07-14T11:00:00+00:00'];
        yield 'a running range run' => [OrderSyncTrigger::Range, '2031-07-15T11:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('nonBlockingRuns')]
    public function a_stale_schedule_run_or_a_range_run_does_not_block_the_run(OrderSyncTrigger $trigger, string $requestedAt): void
    {
        $this->connect();
        $this->insertFixture();
        $runId = $this->recordRun($trigger, 'running', $requestedAt);

        $this->handle();

        self::assertSame(self::CANDIDATES, $this->chunkJobs()[0]->run->chunk->references);
        self::assertSame(2, DB::table('quickbooks.order_sync_runs')->count());
        self::assertNotSame($runId, $this->chunkJobs()[0]->run->chunk->runId);
    }

    #[Test]
    public function no_stored_connection_refuses_the_run(): void
    {
        $this->insertFixture();

        $this->handle();

        $this->assertNothingRun('error', 'Refused QuickBooks order catch-up sync', CatchUpOrderSyncResult::REFUSED_NO_CONNECTION);
    }

    #[Test]
    public function an_unresolved_mapping_key_refuses_the_run_naming_it(): void
    {
        $this->connect();
        $this->insertFixture();
        $key = DB::table('quickbooks.mappings')->orderBy('key')->value('key');
        self::assertIsString($key);
        DB::table('quickbooks.mappings')->where('key', $key)->update(['qbo_ref_id' => null, 'qbo_ref_name' => null]);

        $this->handle();

        $context = $this->assertNothingRun('error', 'Refused QuickBooks order catch-up sync', CatchUpOrderSyncResult::REFUSED_MAPPINGS_UNRESOLVED);
        self::assertSame([$key], $context['missing_keys']);
    }

    #[Test]
    public function a_rejected_token_refresh_refuses_the_run(): void
    {
        $this->saveConnection();
        $this->insertFixture();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->handle();

        $this->assertNothingRun('error', 'Refused QuickBooks order catch-up sync', CatchUpOrderSyncResult::REFUSED_TOKEN_REJECTED);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedSettings(): iterable
    {
        yield 'floor unset' => [['quickbooks.backfill_floor_date' => null], CatchUpOrderSyncResult::REFUSED_FLOOR_UNSET];
        yield 'schedule start not a date' => [['quickbooks.order_sync_schedule_from' => '11/07/2031'], CatchUpOrderSyncResult::REFUSED_SCHEDULE_FROM_MALFORMED];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('refusedSettings')]
    public function a_missing_floor_or_a_malformed_schedule_start_refuses_the_run(array $config, string $reason): void
    {
        $this->connect();
        $this->insertFixture();
        \config($config);

        $this->handle();

        $this->assertNothingRun('error', 'Refused QuickBooks order catch-up sync', $reason);
    }

    private function insertFixture(): void
    {
        foreach (self::CANDIDATES as $reference) {
            $this->insertOrder($reference, self::RECENT);
        }
        $this->insertSync(890000002, 'pending', null);
        $this->insertSync(890000003, 'failed', 'item_missing');
        $this->insertOrder(890000004, '2031-07-05 10:00:00+00');
        foreach ([890000005 => ['synced', null], 890000006 => ['failed', 'qbo_validation']] as $reference => [$status, $code]) {
            $this->insertOrder($reference, self::RECENT);
            $this->insertSync($reference, $status, $code);
        }
        $this->insertOrder(890000007, '2031-07-12 12:00:00+00');
        $this->insertSync(890000007, 'failed', 'item_missing');
        $this->insertOrder(890000008, self::RECENT, ['lifecycle_status' => 'cancelled']);
        $this->insertOrder(890000009, '2031-07-15 11:30:00+00');
    }

    private function insertSync(int $reference, string $status, ?string $errorCode): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => $reference,
            'order_external_id' => $reference,
            'status' => $status,
            'last_error_code' => $errorCode,
            'attempts' => $status === 'pending' ? 0 : 1,
        ]);
    }

    private function recordRun(OrderSyncTrigger $trigger, string $status, string $requestedAt): string
    {
        $runId = (string) Str::uuid();
        $range = $trigger === OrderSyncTrigger::Range ? new DateRange(new DateTimeImmutable('2031-07-01'), new DateTimeImmutable('2031-07-02')) : null;
        $this->app->make(OrderSyncRunRepositoryInterface::class)->record(new RequestedOrderSyncRunDTO($runId, $trigger, false, $range, null, 1, new DateTimeImmutable($requestedAt)));
        DB::table('quickbooks.order_sync_runs')->where('id', $runId)->update(['status' => $status]);

        return $runId;
    }

    private function handle(): SyncJob
    {
        return $this->work(new SyncQuickBooksOrderCatchUpJob());
    }

    /**
     * @param Closure(QueuedOrderSyncDispatcher, OrderChunkRunDTO): void $dispatch
     */
    private function dispatchThrough(Closure $dispatch): void
    {
        $real = $this->app->make(QueuedOrderSyncDispatcher::class);
        $this->app->instance(OrderSyncDispatcherInterface::class, new readonly class ($real, $dispatch) implements OrderSyncDispatcherInterface {
            /**
             * @param Closure(QueuedOrderSyncDispatcher, OrderChunkRunDTO): void $dispatch
             */
            public function __construct(private QueuedOrderSyncDispatcher $real, private Closure $dispatch) {}

            #[Override]
            public function dispatchRange(OrderRangeRunDTO $run): void
            {
                $this->real->dispatchRange($run);
            }

            #[Override]
            public function dispatchChunk(OrderChunkRunDTO $run): void
            {
                ($this->dispatch)($this->real, $run);
            }
        });
    }

    private function chunkSyncsEverything(): void
    {
        $chunk = $this->createStub(SyncOrderChunkUseCase::class);
        $chunk->method('execute')->willReturnCallback(static fn(ApiKeyToken $token, OrderChunkRunDTO $run): OrderSyncResultsResult => OrderSyncResultsResult::inRequestOrder(
            $run->chunk->references,
            \array_map(self::synced(...), $run->chunk->references),
        ));
        $this->app->instance(SyncOrderChunkUseCase::class, $chunk);
    }

    /**
     * intentional: built in the DTO's own scope, as its public route to `synced` needs a fully posted order.
     */
    private static function synced(int $reference): OrderSyncResultDTO
    {
        $build = static fn(): OrderSyncResultDTO => new OrderSyncResultDTO($reference, SyncOutcome::Synced, DocType::Invoice, "A{$reference}", "qbo-{$reference}", null, null);

        return Closure::bind($build, null, OrderSyncResultDTO::class)();
    }

    /**
     * @return list<SyncQuickBooksOrderChunkJob>
     */
    private function chunkJobs(): array
    {
        return \array_values(Queue::pushed(SyncQuickBooksOrderChunkJob::class)->all());
    }

    /**
     * @return array{string, bool, int} status, whether finished, open jobs
     */
    private function runState(): array
    {
        $run = $this->runRow();

        return [$run->status, $run->finished_at !== null, $run->open_jobs];
    }

    private function runRow(): stdClass
    {
        $run = DB::table('quickbooks.order_sync_runs')->sole();
        self::assertInstanceOf(stdClass::class, $run);

        return $run;
    }

    /**
     * @return array<string, mixed> the logged entry's context
     */
    private function assertNothingRun(string $level, string $message, string $reason): array
    {
        self::assertSame([], $this->chunkJobs());
        self::assertSame(0, DB::table('quickbooks.order_sync_runs')->count());

        return $this->assertLogged($level, $message, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertLogged(string $level, string $message, string $reason): array
    {
        $entries = \array_values(\array_filter($this->logs, static fn(array $log): bool => $log[1] === $message));
        self::assertCount(1, $entries);
        self::assertSame([$level, $reason], [$entries[0][0], $entries[0][2]['reason'] ?? null]);

        return $entries[0][2];
    }

    private function connect(): void
    {
        $this->saveConnection();
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('test-access-token'), CarbonImmutable::now()->addHour());
    }

    private function saveConnection(): void
    {
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('test-refresh-token'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
    }
}
