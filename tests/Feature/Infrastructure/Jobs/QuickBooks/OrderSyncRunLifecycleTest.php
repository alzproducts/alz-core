<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\Dispatchers\QueuedOrderSyncDispatcher;
use App\Infrastructure\QuickBooks\EloquentOrderSyncRunRepository;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\Fixtures\QuickBooks\FakeSlotRedis;
use Tests\TestCase;

/**
 * Runs real chunk jobs against a recorded run, through the dispatcher's open-job tally.
 */
#[CoversClass(EloquentOrderSyncRunRepository::class)]
#[CoversClass(QueuedOrderSyncDispatcher::class)]
#[CoversClass(SyncQuickBooksOrderChunkJob::class)]
final class OrderSyncRunLifecycleTest extends TestCase
{
    use DatabaseTransactions;
    use RunsQueuedJobs;

    private const string REALM = '9130000000000001';

    private string $runId;

    /** @var list<int> chunk attempts synced so far, in order */
    private array $attempts = [];

    /** @var int how many pushed jobs have been worked */
    private int $worked = 0;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        \config(['quickbooks.backfill_floor_date' => '2026-01-01']);
        $this->travelTo(new CarbonImmutable('2026-11-24T12:00:00+00:00'));
        $realm = $this->createStub(QuickBooksRealmInterface::class);
        $realm->method('realmId')->willReturn(self::REALM);
        $this->app->instance(QuickBooksRealmInterface::class, $realm);
        $this->app->instance(QuickBooksOrderChunkSlot::class, new QuickBooksOrderChunkSlot(new FakeSlotRedis(), new NullLogger(), 1));
        $tokens = $this->createStub(QuickBooksTokenService::class);
        $tokens->method('accessToken')->willReturn(new ApiKeyToken('test-access-token'));
        $this->app->instance(QuickBooksTokenService::class, $tokens);
        $this->runId = (string) Str::uuid();
    }

    #[Test]
    public function a_two_chunk_run_starts_with_its_first_job_and_completes_with_both_chunks_summed(): void
    {
        $this->record();
        $this->chunkSyncs(static fn(OrderSyncChunkDTO $chunk): array => [
            1 => self::synced(1), 2 => self::refused(2, SyncReasonCode::OrderNotFound),
            3 => self::refused(3, SyncReasonCode::ItemMissing), 4 => self::synced(4),
        ]);
        $this->dispatch([1, 2]);
        $this->dispatch([3, 4]);
        self::assertSame(['queued', false, false, 2], $this->state());

        $this->workNext();

        $run = $this->row();
        self::assertSame('running', $run->status);
        self::assertNotNull($run->started_at);
        self::assertNull($run->finished_at);
        self::assertSame([1, 0, 1, 0, 0], $this->counts());

        $this->workNext();

        self::assertSame(['completed', true, true, 0], $this->state());
        self::assertSame([2, 0, 1, 1, 0], $this->counts());
    }

    #[Test]
    public function a_reference_re_attempted_after_the_deadline_counts_once_as_synced(): void
    {
        $this->record();
        $this->chunkSyncs(fn(OrderSyncChunkDTO $chunk): array => $this->attempts === [1]
            ? [1 => self::synced(1), 2 => self::refused(2, SyncReasonCode::DeadlineExceeded)]
            : [2 => self::synced(2)]);
        $this->dispatch([1, 2]);

        $this->workNext();

        self::assertSame(['running', 1], [$this->row()->status, $this->row()->open_jobs]);
        self::assertSame([1, 0, 0, 0, 0], $this->counts());

        $this->workNext();

        self::assertSame([1, 2], $this->attempts);
        self::assertSame('completed', $this->row()->status);
        self::assertSame([2, 0, 0, 0, 0], $this->counts());
    }

    #[Test]
    public function the_last_attempt_with_references_past_the_deadline_ends_the_run_failed_counting_them_not_attempted(): void
    {
        $this->record();
        $this->chunkSyncs(static fn(OrderSyncChunkDTO $chunk): array => [
            1 => self::synced(1), 2 => self::refused(2, SyncReasonCode::DeadlineExceeded),
        ]);
        $this->dispatch([1, 2], attempt: 6);

        self::assertTrue($this->workNext()->hasFailed());

        self::assertSame(['failed', true, true, 0], $this->state());
        self::assertSame([1, 0, 0, 0, 1], $this->counts());
    }

    #[Test]
    public function the_only_open_job_reaching_its_failure_handler_ends_the_run_failed(): void
    {
        $this->record();
        $this->chunkThrows();
        $this->dispatch([1, 2]);

        self::assertTrue($this->workNext()->hasFailed());

        self::assertSame(['failed', true, true, 0], $this->state());
    }

    #[Test]
    public function a_failed_chunk_leaves_the_run_running_until_the_other_chunk_ends_then_failed(): void
    {
        $this->record();
        $this->chunkSyncs(static function (OrderSyncChunkDTO $chunk): array {
            if ($chunk->references === [1, 2]) {
                throw new RuntimeException('test');
            }

            return [3 => self::synced(3), 4 => self::synced(4)];
        });
        $this->dispatch([1, 2]);
        $this->dispatch([3, 4]);

        self::assertTrue($this->workNext()->hasFailed());

        self::assertSame(['running', true, false, 1], $this->state());

        self::assertFalse($this->workNext()->hasFailed());

        self::assertSame(['failed', true, true, 0], $this->state());
        self::assertSame([2, 0, 0, 0, 0], $this->counts());
    }

    #[Test]
    public function a_chunk_whose_run_has_no_row_syncs_and_writes_none(): void
    {
        $this->chunkSyncs(static fn(OrderSyncChunkDTO $chunk): array => [1 => self::synced(1)]);
        $this->dispatch([1]);

        self::assertFalse($this->workNext()->hasFailed());

        self::assertSame([1], $this->attempts);
        self::assertSame(0, DB::table('quickbooks.order_sync_runs')->where('id', $this->runId)->count());
    }

    private function record(): void
    {
        $this->app->make(OrderSyncRunRepositoryInterface::class)->record(new RequestedOrderSyncRunDTO(
            $this->runId,
            OrderSyncTrigger::Range,
            false,
            new DateRange(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-02')),
            null,
            4,
            new DateTimeImmutable('2026-11-24T11:59:00+00:00'),
        ));
    }

    /**
     * @param non-empty-list<int> $references
     */
    private function dispatch(array $references, int $attempt = 1): void
    {
        $this->app->make(OrderSyncDispatcherInterface::class)->dispatchChunk(
            new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, $references, false, OrderSyncTrigger::Range, $this->runId), $attempt),
        );
    }

    private function workNext(): SyncJob
    {
        $job = Queue::pushed(SyncQuickBooksOrderChunkJob::class)->values()[$this->worked] ?? self::fail('No job left to work.');
        ++$this->worked;

        return $this->work($job);
    }

    /**
     * @return array{string, bool, bool, int} status, whether started and finished, open jobs
     */
    private function state(): array
    {
        $run = $this->row();

        return [$run->status, $run->started_at !== null, $run->finished_at !== null, $run->open_jobs];
    }

    /**
     * @return list<int> synced, already synced, skipped, failed, not attempted
     */
    private function counts(): array
    {
        $run = $this->row();

        return [$run->synced, $run->already_synced, $run->skipped, $run->failed, $run->not_attempted];
    }

    private function row(): stdClass
    {
        $run = DB::table('quickbooks.order_sync_runs')->where('id', $this->runId)->first();
        self::assertInstanceOf(stdClass::class, $run);

        return $run;
    }

    /**
     * @param Closure(OrderSyncChunkDTO): array<int, OrderSyncResultDTO> $results by reference
     */
    private function chunkSyncs(Closure $results): void
    {
        $chunk = $this->createStub(SyncOrderChunkUseCase::class);
        $chunk->method('execute')->willReturnCallback(function (ApiKeyToken $token, OrderChunkRunDTO $run) use ($results): OrderSyncResultsResult {
            $this->attempts[] = $run->attempt;

            return OrderSyncResultsResult::inRequestOrder($run->chunk->references, \array_values($results($run->chunk)));
        });
        $this->app->instance(SyncOrderChunkUseCase::class, $chunk);
    }

    private function chunkThrows(): void
    {
        $this->chunkSyncs(static fn(): never => throw new RuntimeException('test'));
    }

    private static function refused(int $reference, SyncReasonCode $reason): OrderSyncResultDTO
    {
        return OrderSyncResultDTO::refused($reference, $reason);
    }

    /**
     * intentional: built in the DTO's own scope, as its public route to `synced` needs a fully posted order.
     */
    private static function synced(int $reference): OrderSyncResultDTO
    {
        $build = static fn(): OrderSyncResultDTO => new OrderSyncResultDTO($reference, SyncOutcome::Synced, DocType::Invoice, "A{$reference}", "qbo-{$reference}", null, null);

        return Closure::bind($build, null, OrderSyncResultDTO::class)();
    }
}
