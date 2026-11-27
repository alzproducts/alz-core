<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Limiters\ConcurrencyLimiter;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

/**
 * Real Redis, at the default number of slots, under keys of its own.
 */
#[CoversClass(QuickBooksOrderChunkSlot::class)]
#[Group('redis')]
final class QuickBooksOrderChunkSlotRedisTest extends TestCase
{
    use DatabaseTransactions;
    use RunsQueuedJobs;
    use SendsRoleTokens;

    private const string REALM = '9130000000000001';

    private const string KEY = QuickBooksOrderChunkSlot::NAME . '1';

    /** @var list<OrderSyncChunkDTO> */
    private array $synced = [];

    private ?Closure $duringSync = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        \config(['quickbooks.backfill_floor_date' => '2026-01-01']);
        \config([
            'database.redis.' . QuickBooksRateLimiter::CONNECTION . '.prefix' => 'test-' . Str::uuid()->toString() . ':',
            'quickbooks.order_sync_concurrency' => $this->defaultConcurrency(),
        ]);
        $this->app->forgetInstance('redis');
        $realm = $this->createStub(QuickBooksRealmInterface::class);
        $realm->method('realmId')->willReturn(self::REALM);
        $this->app->instance(QuickBooksRealmInterface::class, $realm);
        $tokens = $this->createStub(QuickBooksTokenService::class);
        $tokens->method('accessToken')->willReturn(new ApiKeyToken('test-access-token'));
        $this->app->instance(QuickBooksTokenService::class, $tokens);
        $chunk = $this->createStub(SyncOrderChunkUseCase::class);
        $chunk->method('execute')->willReturnCallback(function (ApiKeyToken $token, OrderChunkRunDTO $run): OrderSyncResultsResult {
            $this->synced[] = $run->chunk;
            $this->duringSync?->__invoke();

            return OrderSyncResultsResult::inRequestOrder([1], [OrderSyncResultDTO::refused(1, SyncReasonCode::OrderNotFound)]);
        });
        $this->app->instance(SyncOrderChunkUseCase::class, $chunk);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->connection()->command('del', [self::KEY]);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runs(): iterable
    {
        yield 'the same run' => ['run-1'];
        yield 'another run' => ['run-2'];
    }

    #[Test]
    #[DataProvider('runs')]
    public function a_second_chunk_waits_while_one_holds_the_slot(string $runId): void
    {
        $this->slot()->run(function () use ($runId): void {
            self::assertFalse($this->work(self::job($runId))->hasFailed());
        });

        self::assertSame([], $this->synced);
        $copies = Queue::pushed(SyncQuickBooksOrderChunkJob::class)->values();
        self::assertCount(1, $copies);
        self::assertEquals(self::job($runId)->run, $copies[0]->run ?? null);
        self::assertContains($copies[0]->delay ?? null, \range(20, 40));

        $this->work(self::job($runId));
        self::assertCount(1, $this->synced);
    }

    #[Test]
    public function a_requested_chunk_waits_while_a_range_chunk_holds_the_slot(): void
    {
        $this->fakeSupabaseJwks();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        \config(['quickbooks.backfill_floor_date' => '2026-07-01']);
        $this->asRole('standard')->postJson('/api/quickbooks/orders/sync', ['references' => [890000001]])->assertStatus(202);
        $requested = Queue::pushed(SyncQuickBooksOrderChunkJob::class)->sole();
        self::assertInstanceOf(SyncQuickBooksOrderChunkJob::class, $requested);
        $this->duringSync = function () use ($requested): void {
            self::assertFalse($this->work($requested)->hasFailed());
        };

        self::assertFalse($this->work(self::job('range-run'))->hasFailed());

        self::assertSame([OrderSyncTrigger::Range], \array_column($this->synced, 'trigger'));
        $copies = Queue::pushed(SyncQuickBooksOrderChunkJob::class)->values()->slice(1)->values();
        self::assertCount(1, $copies);
        self::assertEquals($requested->run, $copies[0]->run ?? null);
        self::assertContains($copies[0]->delay ?? null, \range(20, 40));
    }

    #[Test]
    public function ten_waits_in_a_row_leave_it_queued_and_not_failed(): void
    {
        $this->slot()->run(function (): void {
            $job = self::job('run-1');
            for ($wait = 1; $wait <= 10; ++$wait) {
                self::assertFalse($this->work($job)->hasFailed());
                $copies = Queue::pushed(SyncQuickBooksOrderChunkJob::class);
                self::assertCount($wait, $copies);
                $job = $copies->last();
                self::assertInstanceOf(SyncQuickBooksOrderChunkJob::class, $job);
            }
        });

        self::assertSame([], $this->synced);
    }

    #[Test]
    public function the_slot_is_held_while_the_chunk_runs_and_freed_after(): void
    {
        $this->slot()->run(function (): void {
            $this->assertHeldForNearly(QuickBooksOrderChunkSlot::HOLD_SECONDS);
        });

        self::assertSame(-2, $this->connection()->command('ttl', [self::KEY]));
    }

    #[Test]
    public function a_killed_holder_keeps_the_slot_until_its_hold_ends(): void
    {
        new ConcurrencyLimiter($this->connection(), QuickBooksOrderChunkSlot::NAME, 1, QuickBooksOrderChunkSlot::HOLD_SECONDS)->block(0);

        $this->work(self::job('run-1'));

        self::assertSame([], $this->synced);
        $this->assertHeldForNearly(QuickBooksOrderChunkSlot::HOLD_SECONDS);

        $this->connection()->command('pexpire', [self::KEY, 50]);
        \usleep(100_000);
        $this->work(self::job('run-1'));

        self::assertCount(1, $this->synced);
    }

    private function assertHeldForNearly(int $seconds): void
    {
        $ttl = $this->connection()->command('ttl', [self::KEY]);
        self::assertIsInt($ttl);
        self::assertLessThanOrEqual($seconds, $ttl);
        self::assertGreaterThan($seconds - 10, $ttl);
    }

    private function defaultConcurrency(): mixed
    {
        Env::getRepository()->clear('QUICKBOOKS_ORDER_SYNC_CONCURRENCY');
        $config = require \config_path('quickbooks.php');
        self::assertIsArray($config);

        return $config['order_sync_concurrency'] ?? null;
    }

    private function slot(): QuickBooksOrderChunkSlot
    {
        return $this->app->make(QuickBooksOrderChunkSlot::class);
    }

    private function connection(): Connection
    {
        return $this->app->make(RedisFactory::class)->connection(QuickBooksRateLimiter::CONNECTION);
    }

    private static function job(string $runId): SyncQuickBooksOrderChunkJob
    {
        return new SyncQuickBooksOrderChunkJob(
            new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, [1], false, OrderSyncTrigger::Range, $runId)),
        );
    }
}
