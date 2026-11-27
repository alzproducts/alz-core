<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderRangeJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\TestCase;
use Tests\Unit\Infrastructure\QuickBooks\RecordingLogger;

/**
 * The range lies in 2031, where no seeded order is placed.
 */
#[CoversClass(SyncQuickBooksOrderRangeJob::class)]
final class SyncQuickBooksOrderRangeJobTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;
    use RunsQueuedJobs;

    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private const int REFERENCE = 890000001;

    private RecordingLogger $failureLog;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->failureLog = new RecordingLogger();
        $this->app->instance(HandleOrderSyncRunFailureUseCase::class, new HandleOrderSyncRunFailureUseCase($this->app->make(OrderSyncRunRepositoryInterface::class), $this->failureLog));
    }

    #[Test]
    public function exactly_the_orders_in_range_are_queued_on_low_in_chunks_of_at_most_20(): void
    {
        $this->insertOrder(self::REFERENCE, '2031-07-14 22:59:59+00');
        for ($i = 1; $i <= 21; ++$i) {
            $this->insertOrder(self::REFERENCE + $i, '2031-07-15 10:00:00+00');
        }
        $this->insertOrder(self::REFERENCE + 22, '2031-07-15 23:00:00+00');

        self::assertFalse($this->work(self::job())->hasFailed());

        Queue::assertCount(2);
        $chunks = Queue::pushed(SyncQuickBooksOrderChunkJob::class, static fn(SyncQuickBooksOrderChunkJob $job, ?string $queue): bool => $queue === 'low')->values();
        self::assertCount(2, $chunks);
        $references = [];
        foreach ($chunks as $job) {
            self::assertLessThanOrEqual(20, \count($job->run->chunk->references));
            self::assertNull($job->delay);
            self::assertSame(
                [self::REALM, true, 'range', self::RUN_ID, 1],
                [$job->run->chunk->realmId, $job->run->chunk->forceRetry, $job->run->chunk->trigger->value, $job->run->chunk->runId, $job->run->attempt],
            );
            $references = [...$references, ...$job->run->chunk->references];
        }
        \sort($references);
        self::assertSame(\range(self::REFERENCE + 1, self::REFERENCE + 21), $references);
    }

    #[Test]
    public function the_run_takes_the_candidate_count_the_range_job_dispatched_and_stays_open_for_its_chunk(): void
    {
        $runs = $this->app->make(OrderSyncRunRepositoryInterface::class);
        $runs->record(new RequestedOrderSyncRunDTO(
            self::RUN_ID,
            OrderSyncTrigger::Range,
            true,
            new DateRange(new DateTimeImmutable('2031-07-15'), new DateTimeImmutable('2031-07-15')),
            null,
            5,
            new DateTimeImmutable('2031-07-15T12:00:00+00:00'),
        ));
        $runs->addOpenJob(self::RUN_ID);
        for ($i = 1; $i <= 3; ++$i) {
            $this->insertOrder(self::REFERENCE + $i, '2031-07-15 10:00:00+00');
        }

        self::assertFalse($this->work(self::job())->hasFailed());

        $run = DB::table('quickbooks.order_sync_runs')->where('id', self::RUN_ID)->first(['candidate_count', 'open_jobs', 'status', 'finished_at']);
        self::assertEquals((object) ['candidate_count' => 3, 'open_jobs' => 1, 'status' => 'running', 'finished_at' => null], $run);
    }

    #[Test]
    public function a_throw_fails_it_after_one_call(): void
    {
        $sourceOrders = $this->createMock(OrderSyncSourceQueryRepositoryInterface::class);
        $sourceOrders->expects($this->once())->method('referencesPlacedIn')->willThrowException(new RuntimeException('test'));
        $this->app->instance(OrderSyncSourceQueryRepositoryInterface::class, $sourceOrders);

        $job = $this->work(self::job());

        self::assertTrue($job->hasFailed());
        self::assertFalse($job->isReleased());
        $this->assertLoggedFailureAndNothingQueued(RuntimeException::class);
    }

    #[Test]
    public function a_timeout_dispatches_nothing_more(): void
    {
        $this->insertOrder(self::REFERENCE, '2031-07-15 10:00:00+00');

        $this->timeOut(self::job());

        $this->assertLoggedFailureAndNothingQueued(TimeoutExceededException::class);
    }

    #[Test]
    public function a_redelivered_payload_dispatches_no_second_set_of_chunks(): void
    {
        $this->insertOrder(self::REFERENCE, '2031-07-15 10:00:00+00');

        self::assertTrue($this->work(self::job(), delivery: 2)->hasFailed());

        $this->assertLoggedFailureAndNothingQueued(MaxAttemptsExceededException::class);
    }

    private function assertLoggedFailureAndNothingQueued(string $exception): void
    {
        self::assertSame([['QuickBooks order range run failed', [
            'run_id' => self::RUN_ID,
            'from' => '2031-07-15',
            'to' => '2031-07-15',
            'force_retry' => true,
            'exception' => $exception,
        ]]], $this->failureLog->records);
        Queue::assertNothingPushed();
    }

    private static function job(): SyncQuickBooksOrderRangeJob
    {
        return new SyncQuickBooksOrderRangeJob(
            new OrderRangeRunDTO(self::REALM, new DateRange(new DateTimeImmutable('2031-07-15'), new DateTimeImmutable('2031-07-15')), true, self::RUN_ID),
        );
    }
}
