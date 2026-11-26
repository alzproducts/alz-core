<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\Dispatchers\QueuedOrderSyncDispatcher;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlotAttempt;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\Fixtures\QuickBooks\FakeSlotRedis;
use Tests\TestCase;
use Tests\Unit\Infrastructure\QuickBooks\RecordingLogger;
use Throwable;

#[CoversClass(SyncQuickBooksOrderChunkJob::class)]
#[CoversClass(QueuedOrderSyncDispatcher::class)]
#[CoversClass(QuickBooksOrderChunkSlot::class)]
#[CoversClass(QuickBooksOrderChunkSlotAttempt::class)]
final class SyncQuickBooksOrderChunkJobTest extends TestCase
{
    use RunsQueuedJobs;

    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private FakeSlotRedis $redis;

    private RecordingLogger $slotLog;

    private RecordingLogger $failureLog;

    /** @var list<array{OrderSyncChunkDTO, Deadline}> */
    private array $synced = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        \config(['quickbooks.backfill_floor_date' => '2026-01-01']);
        $this->travelTo(new CarbonImmutable('2026-11-24T12:00:00+00:00'));
        $this->redis = new FakeSlotRedis();
        $this->slotLog = new RecordingLogger();
        $this->failureLog = new RecordingLogger();
        $realm = $this->createStub(QuickBooksRealmInterface::class);
        $realm->method('realmId')->willReturn(self::REALM);
        $this->app->instance(QuickBooksRealmInterface::class, $realm);
        $this->app->instance(QuickBooksOrderChunkSlot::class, new QuickBooksOrderChunkSlot($this->redis, $this->slotLog, 1));
        $this->app->instance(HandleOrderSyncRunFailureUseCase::class, new HandleOrderSyncRunFailureUseCase($this->failureLog));
        $this->tokenIs(static fn(): ApiKeyToken => new ApiKeyToken('test-access-token'));
        $this->syncs([]);
    }

    #[Test]
    public function the_chunk_is_synced_with_its_run_and_a_deadline_from_when_the_slot_was_taken(): void
    {
        $job = $this->work(self::job());

        self::assertFalse($job->hasFailed());
        self::assertCount(1, $this->synced);
        [$chunk, $deadline] = $this->synced[0];
        self::assertEquals(self::chunkRun()->chunk, $chunk);
        self::assertSame(OrderSyncTrigger::Range, $chunk->trigger);
        self::assertSame(self::RUN_ID, $chunk->runId);
        self::assertSame('2026-11-24T12:10:00+00:00', $deadline->at->format(\DATE_ATOM));
        self::assertSame([QuickBooksRateLimiter::CONNECTION], $this->redis->connections);
        self::assertFalse($this->redis->held);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function each_limit_outlasts_the_one_inside_it(): void
    {
        $retryAfter = \config('queue.connections.redis-long.retry_after');

        self::assertIsInt($retryAfter);
        self::assertGreaterThanOrEqual(SyncQuickBooksOrderChunkJob::DEADLINE_SECONDS + 120, self::job()->timeout);
        self::assertGreaterThan(self::job()->timeout, QuickBooksOrderChunkSlot::HOLD_SECONDS);
        self::assertGreaterThan(QuickBooksOrderChunkSlot::HOLD_SECONDS, $retryAfter);
        self::assertSame([600, 900, 960, 10800], [SyncQuickBooksOrderChunkJob::DEADLINE_SECONDS, self::job()->timeout, QuickBooksOrderChunkSlot::HOLD_SECONDS, $retryAfter]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function waitingAttempts(): iterable
    {
        yield 'first attempt' => [1];
        yield 'second attempt' => [2];
    }

    #[Test]
    #[DataProvider('waitingAttempts')]
    public function with_no_free_slot_nothing_is_called_and_one_delayed_copy_waits(int $attempt): void
    {
        $this->redis->free = false;

        $job = $this->work(self::job(attempt: $attempt));

        self::assertFalse($job->hasFailed());
        self::assertFalse($job->isReleased());
        self::assertSame([], $this->synced);
        $copy = $this->onlyCopy();
        self::assertEquals(self::chunkRun(attempt: $attempt), $copy->run);
        self::assertSame('low', $copy->queue);
        self::assertIsInt($copy->delay);
        self::assertGreaterThanOrEqual(20, $copy->delay);
        self::assertLessThanOrEqual(40, $copy->delay);
    }

    #[Test]
    public function waits_are_spread_between_20_and_40_seconds(): void
    {
        $this->redis->free = false;
        for ($i = 0; $i < 100; ++$i) {
            $this->work(self::job());
        }

        $delays = $this->copies()->map(static fn(SyncQuickBooksOrderChunkJob $copy): mixed => $copy->delay)->all();

        self::assertCount(100, $delays);
        self::assertGreaterThan(1, \count(\array_unique($delays)));
        self::assertSame([], \array_diff($delays, \range(20, 40)));
    }

    #[Test]
    public function ten_waits_in_a_row_leave_it_queued_and_not_failed(): void
    {
        $this->redis->free = false;
        $job = self::job();

        for ($wait = 1; $wait <= 10; ++$wait) {
            self::assertFalse($this->work($job)->hasFailed());
            self::assertCount($wait, $this->copies());
            $job = $this->copies()->last();
            self::assertInstanceOf(SyncQuickBooksOrderChunkJob::class, $job);
        }

        self::assertEquals(self::chunkRun(), $job->run);
        self::assertSame([], $this->synced);
        self::assertSame([], $this->failureLog->records);
    }

    #[Test]
    public function waiting_on_a_queue_that_ignores_delays_fails_instead_of_looping(): void
    {
        $this->redis->free = false;
        $queue = $this->createStub(QueueFactory::class);
        $queue->method('connection')->willReturn(new SyncQueue());
        $this->app->instance(QueuedOrderSyncDispatcher::class, new QueuedOrderSyncDispatcher($queue, 1));

        self::assertTrue($this->work(self::job())->hasFailed());

        $this->assertLoggedFailure(InvalidConfigurationException::class, [1, 2, 3, 4]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function what_the_deadline_left_is_re_attempted_after_600_seconds(): void
    {
        $this->syncs([2 => SyncReasonCode::DeadlineExceeded, 4 => SyncReasonCode::DeadlineExceeded]);

        self::assertFalse($this->work(self::job())->hasFailed());

        $copy = $this->onlyCopy();
        self::assertEquals(self::chunkRun(attempt: 2, references: [2, 4]), $copy->run);
        self::assertSame(600, $copy->delay);
    }

    #[Test]
    public function without_a_token_the_whole_chunk_is_re_attempted_after_600_seconds(): void
    {
        $this->tokenIs(static fn(): never => throw new ExternalServiceUnavailableException('QuickBooks'));

        self::assertFalse($this->work(self::job())->hasFailed());

        self::assertSame([], $this->synced);
        $copy = $this->onlyCopy();
        self::assertEquals(self::chunkRun(attempt: 2), $copy->run);
        self::assertSame(600, $copy->delay);
    }

    #[Test]
    public function the_last_attempt_past_the_deadline_fails_and_logs_exactly_what_is_left(): void
    {
        $this->syncs([2 => SyncReasonCode::DeadlineExceeded, 3 => SyncReasonCode::InProgress]);

        self::assertTrue($this->work(self::job(attempt: 6))->hasFailed());

        $this->assertLoggedFailure(OrderChunkUnfinishedException::class, [2]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_last_attempt_without_a_token_fails_and_logs_what_it_was_given(): void
    {
        $this->tokenIs(static fn(): never => throw new ExternalServiceUnavailableException('QuickBooks'));

        self::assertTrue($this->work(self::job(attempt: 6, references: [2, 4]))->hasFailed());

        $this->assertLoggedFailure(OrderChunkUnfinishedException::class, [2, 4]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_connection_or_grant_that_is_gone_fails_it_with_no_re_attempt(): void
    {
        $this->tokenIs(static fn(): never => throw new AuthenticationExpiredException('QuickBooks'));

        self::assertTrue($this->work(self::job(attempt: 2, references: [2, 4]))->hasFailed());

        self::assertSame([], $this->synced);
        $this->assertLoggedFailure(AuthenticationExpiredException::class, [2, 4]);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function throwsFromTheChunk(): iterable
    {
        yield 'rejected access token' => [new AuthenticationExpiredException('QuickBooks')];
        yield 'unexpected' => [new RuntimeException('test')];
        yield 'unavailable' => [new ExternalServiceUnavailableException('QuickBooks')];
        yield 'what the slot throws when none is free' => [new LimiterTimeoutException()];
    }

    #[Test]
    #[DataProvider('throwsFromTheChunk')]
    public function a_throw_from_the_chunk_fails_it_after_one_call(Throwable $failure): void
    {
        $this->syncThrows($failure);

        $job = $this->work(self::job());

        self::assertTrue($job->hasFailed());
        self::assertFalse($job->isReleased());
        self::assertCount(1, $this->synced);
        self::assertFalse($this->redis->held);
        $this->assertLoggedFailure($failure::class, [1, 2, 3, 4]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_timeout_fails_it_with_no_further_call(): void
    {
        $this->timeOut(self::job());

        self::assertSame([], $this->synced);
        $this->assertLoggedFailure(TimeoutExceededException::class, [1, 2, 3, 4]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_redelivered_payload_never_reaches_the_chunk(): void
    {
        self::assertTrue($this->work(self::job(), delivery: 2)->hasFailed());

        self::assertSame([], $this->synced);
        self::assertSame([], $this->redis->connections);
        $this->assertLoggedFailure(MaxAttemptsExceededException::class, [1, 2, 3, 4]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_error_taking_the_slot_fails_it_before_the_chunk(): void
    {
        $this->redis->takeFailure = new RuntimeException('test');

        self::assertTrue($this->work(self::job())->hasFailed());

        self::assertSame([], $this->synced);
        $this->assertLoggedFailure(ExternalServiceUnavailableException::class, [1, 2, 3, 4]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_error_releasing_the_slot_leaves_a_finished_chunk_finished(): void
    {
        $this->redis->releaseFailure = new RuntimeException('test');

        self::assertFalse($this->work(self::job())->hasFailed());

        self::assertCount(1, $this->synced);
        self::assertSame([['QuickBooks order chunk slot not released', ['exception' => RuntimeException::class]]], $this->slotLog->records);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_error_releasing_the_slot_leaves_a_failed_chunk_its_own_failure(): void
    {
        $this->redis->releaseFailure = new RuntimeException('test');
        $this->syncThrows(new AuthenticationExpiredException('QuickBooks'));

        self::assertTrue($this->work(self::job())->hasFailed());

        self::assertCount(1, $this->synced);
        self::assertSame([['QuickBooks order chunk slot not released', ['exception' => RuntimeException::class]]], $this->slotLog->records);
        $this->assertLoggedFailure(AuthenticationExpiredException::class, [1, 2, 3, 4]);
    }

    #[Test]
    public function the_payload_carries_no_token(): void
    {
        $this->work(self::job());

        self::assertStringNotContainsString('test-access-token', \serialize(self::job()));
    }

    /**
     * @param list<int> $references
     */
    private function assertLoggedFailure(string $exception, array $references): void
    {
        self::assertCount(1, $this->failureLog->records);
        [$message, $context] = $this->failureLog->records[0];
        self::assertSame('QuickBooks order chunk failed', $message);
        self::assertSame([self::RUN_ID, $references, $exception], [$context['run_id'] ?? null, $context['references'] ?? null, $context['exception'] ?? null]);
    }

    /**
     * @return Collection<int, SyncQuickBooksOrderChunkJob>
     */
    private function copies(): Collection
    {
        return Queue::pushed(SyncQuickBooksOrderChunkJob::class)->values();
    }

    private function onlyCopy(): SyncQuickBooksOrderChunkJob
    {
        $copies = $this->copies();
        self::assertCount(1, $copies);

        return $copies[0] ?? self::fail('No copy was queued.');
    }

    /**
     * @param Closure(): ApiKeyToken $token
     */
    private function tokenIs(Closure $token): void
    {
        $tokens = $this->createStub(QuickBooksTokenService::class);
        $tokens->method('accessToken')->willReturnCallback($token);
        $this->app->instance(QuickBooksTokenService::class, $tokens);
    }

    /**
     * @param array<int, SyncReasonCode> $reasons by reference; every other one is skipped
     */
    private function syncs(array $reasons): void
    {
        $this->chunkIs(static fn(OrderSyncChunkDTO $chunk): OrderSyncResultsResult => OrderSyncResultsResult::inRequestOrder($chunk->references, \array_map(
            static fn(int $reference): OrderSyncResultDTO => OrderSyncResultDTO::refused($reference, $reasons[$reference] ?? SyncReasonCode::OrderNotFound),
            $chunk->references,
        )));
    }

    private function syncThrows(Throwable $failure): void
    {
        $this->chunkIs(static fn(): never => throw $failure);
    }

    /**
     * @param Closure(OrderSyncChunkDTO): OrderSyncResultsResult $outcome
     */
    private function chunkIs(Closure $outcome): void
    {
        $chunk = $this->createStub(SyncOrderChunkUseCase::class);
        $chunk->method('execute')->willReturnCallback(function (ApiKeyToken $token, OrderChunkRunDTO $run, Deadline $deadline) use ($outcome): OrderSyncResultsResult {
            self::assertTrue($this->redis->held);
            $this->synced[] = [$run->chunk, $deadline];

            return $outcome($run->chunk);
        });
        $this->app->instance(SyncOrderChunkUseCase::class, $chunk);
    }

    /**
     * @param non-empty-list<int> $references
     */
    private static function job(int $attempt = 1, array $references = [1, 2, 3, 4]): SyncQuickBooksOrderChunkJob
    {
        return new SyncQuickBooksOrderChunkJob(self::chunkRun($attempt, $references));
    }

    /**
     * @param non-empty-list<int> $references
     */
    private static function chunkRun(int $attempt = 1, array $references = [1, 2, 3, 4]): OrderChunkRunDTO
    {
        return new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, $references, true, OrderSyncTrigger::Range, self::RUN_ID), $attempt);
    }
}
