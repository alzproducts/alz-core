<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderChunkUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\FakeSlotRedis;
use Tests\TestCase;

/**
 * The backfill floor date, rechecked as each chunk runs. London midnight starting 2026-07-01 is 2026-06-30 23:00 UTC.
 */
#[CoversNothing]
final class SyncOrderChunkFloorTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;
    use RunsQueuedJobs;
    use SendsRoleTokens;

    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private const string BEFORE_FLOOR = '2026-06-30 22:59:59+00';

    private const string FLOOR_START = '2026-06-30 23:00:00+00';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        \config(['quickbooks.backfill_floor_date' => '2026-07-01']);
        Queue::fake();
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting = new FakeQuickBooksOrderPosting());
        $this->connect();
    }

    #[Test]
    public function an_order_imported_before_floor_after_the_request_is_accepted_is_not_posted(): void
    {
        $this->acceptThenImportBeforeFloor();

        self::assertSame([], $this->posting->calls);
        self::assertSame([], $this->posting->documents);
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'before_floor_date', 'qbo_txn_id' => null]);
    }

    #[Test]
    public function an_order_imported_before_floor_after_the_request_is_accepted_is_in_the_failed_list(): void
    {
        $this->acceptThenImportBeforeFloor();

        $response = $this->asRole('standard')->getJson('/api/quickbooks/orders/failed')->assertOk();

        self::assertSame([self::REFERENCE], \array_column($response->json('data'), 'reference'));
        $response->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.reason.code', 'before_floor_date')
            ->assertJsonPath('data.0.reason.retryable', false)
            ->assertJsonPath('data.0.reason.message', 'The order was placed before the QuickBooks backfill floor date.');
    }

    #[Test]
    public function a_range_chunk_records_an_order_before_the_floor_as_failed(): void
    {
        $this->insertOrder(self::REFERENCE, self::BEFORE_FLOOR);

        $result = $this->syncChunk([self::REFERENCE], OrderSyncTrigger::Range)[0];

        self::assertSame(['failed', 'before_floor_date'], [$result->outcome->value, $result->reason?->value]);
        self::assertSame([], $this->posting->calls);
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'before_floor_date']);
        self::assertSame(['before_floor_date'], DB::table('quickbooks.order_sync_errors')->pluck('reason_code')->all());
    }

    #[Test]
    public function the_order_on_or_after_the_floor_is_posted_beside_one_before_it(): void
    {
        $this->insertOrder(self::REFERENCE, self::BEFORE_FLOOR);
        $this->insertOrder(self::OTHER_REFERENCE, '2026-07-02 10:00:00+00');

        $results = $this->syncChunk([self::REFERENCE, self::OTHER_REFERENCE], OrderSyncTrigger::Manual);

        self::assertSame(['failed', 'synced'], \array_map(static fn(OrderSyncResultDTO $result): string => $result->outcome->value, $results));
        self::assertSame(['A' . self::OTHER_REFERENCE], $this->postedDocNumbers());
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'before_floor_date']);
        $this->assertRow(self::OTHER_REFERENCE, ['status' => 'synced', 'last_error_code' => null]);
    }

    #[Test]
    public function an_order_placed_at_london_midnight_on_the_floor_date_is_posted(): void
    {
        $this->insertOrder(self::REFERENCE, self::FLOOR_START);

        $result = $this->syncChunk([self::REFERENCE], OrderSyncTrigger::Manual)[0];

        self::assertSame('synced', $result->outcome->value);
        self::assertSame(['A' . self::REFERENCE], $this->postedDocNumbers());
        $this->assertRow(self::REFERENCE, ['status' => 'synced']);
    }

    #[Test]
    public function an_order_before_the_floor_that_quickbooks_already_holds_is_failed_not_synced(): void
    {
        $this->insertOrder(self::REFERENCE, self::BEFORE_FLOOR);
        $this->posting->existing = [new ExistingDocumentDTO('A' . self::REFERENCE, 'qbo-7', DocType::Invoice)];

        $result = $this->syncChunk([self::REFERENCE], OrderSyncTrigger::Manual)[0];

        self::assertSame(['failed', 'before_floor_date'], [$result->outcome->value, $result->reason?->value]);
        self::assertSame([], $this->posting->calls);
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'before_floor_date', 'qbo_txn_id' => null]);
    }

    #[Test]
    public function an_order_before_the_floor_keeps_a_stored_unknown_outcome(): void
    {
        $this->insertOrder(self::REFERENCE, self::BEFORE_FLOOR);
        $this->insertSync(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);

        $result = $this->syncChunk([self::REFERENCE], OrderSyncTrigger::Manual, forceRetry: true)[0];

        self::assertSame(['failed', 'outcome_unknown'], [$result->outcome->value, $result->reason?->value]);
        self::assertSame([], $this->posting->calls);
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        self::assertStringContainsString('before_floor_date', (string) DB::table('quickbooks.order_syncs')->value('last_error_message'));
        self::assertSame(['before_floor_date'], DB::table('quickbooks.order_sync_errors')->pluck('reason_code')->all());
    }

    #[Test]
    public function an_unset_floor_fails_the_chunk_before_any_row_or_quickbooks_call(): void
    {
        $this->insertOrder(self::REFERENCE, self::FLOOR_START);
        $this->insertOrder(self::OTHER_REFERENCE, self::FLOOR_START);
        $this->insertSync(self::OTHER_REFERENCE, [
            'status' => 'processing',
            'claim_token' => '00000000-0000-4000-8000-000000000001',
            'lease_expires_at' => '2026-01-01 00:00:00+00',
            'last_error_code' => 'qbo_unavailable',
            'last_error_message' => 'QuickBooks was unavailable and nothing was posted.',
        ]);
        DB::table('quickbooks.order_sync_errors')->insert([
            'order_sync_id' => DB::table('quickbooks.order_syncs')->value('id'),
            'trigger' => 'manual',
            'reason_code' => 'qbo_unavailable',
            'message' => 'QuickBooks was unavailable and nothing was posted.',
        ]);
        $rows = $this->syncTables();
        \config(['quickbooks.backfill_floor_date' => null]);

        try {
            $this->app->make(RunOrderChunkUseCase::class)->execute(
                new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, [self::REFERENCE, self::OTHER_REFERENCE], true, OrderSyncTrigger::Manual, null)),
                Deadline::after(CarbonImmutable::now()->toDateTimeImmutable(), SyncQuickBooksOrderChunkJob::DEADLINE_SECONDS),
            );
            self::fail('The chunk did not fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('QUICKBOOKS_BACKFILL_FLOOR_DATE', $e->configKey);
        }

        self::assertSame(0, DB::table('quickbooks.order_syncs')->where('reference', self::REFERENCE)->count());
        self::assertEquals($rows, $this->syncTables());
        self::assertSame([], $this->posting->calls);
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function syncTables(): array
    {
        $rows = static fn(string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn(object $row): array => (array) $row)->all();

        return [$rows('quickbooks.order_syncs'), $rows('quickbooks.order_sync_errors')];
    }

    /**
     * The request passes the floor because the order is not local yet; it is imported before the job runs.
     */
    private function acceptThenImportBeforeFloor(): void
    {
        $this->app->instance(QuickBooksOrderChunkSlot::class, new QuickBooksOrderChunkSlot(new FakeSlotRedis(), new NullLogger(), 1));
        $this->asRole('standard')->postJson('/api/quickbooks/orders/sync', ['references' => [self::REFERENCE]])->assertStatus(202);
        $this->insertOrder(self::REFERENCE, self::BEFORE_FLOOR);

        Queue::assertCount(1);
        $job = $this->work(\array_values(Queue::pushed(SyncQuickBooksOrderChunkJob::class)->all())[0]);

        self::assertFalse($job->hasFailed());
    }

    /**
     * @param non-empty-list<int> $references
     *
     * @return list<OrderSyncResultDTO>
     */
    private function syncChunk(array $references, OrderSyncTrigger $trigger, bool $forceRetry = false): array
    {
        $runId = $trigger === OrderSyncTrigger::Range ? self::RUN_ID : null;
        $chunk = new OrderSyncChunkDTO(self::REALM, $references, $forceRetry, $trigger, $runId);

        return $this->app->make(SyncOrderChunkUseCase::class)->execute(
            new ApiKeyToken('test-access-token'),
            new OrderChunkRunDTO($chunk),
            Deadline::after(CarbonImmutable::now()->toDateTimeImmutable(), SyncQuickBooksOrderChunkJob::DEADLINE_SECONDS),
        )->results;
    }

    /**
     * @return list<string>
     */
    private function postedDocNumbers(): array
    {
        $docNumbers = [];
        foreach ($this->posting->documents as $document) {
            $docNumbers[] = $document->draft->core->docNumber;
        }

        return $docNumbers;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertRow(int $reference, array $expected): void
    {
        self::assertSame($expected, (array) DB::table('quickbooks.order_syncs')->where('reference', $reference)->first(\array_keys($expected)));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function insertSync(int $reference, array $state): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => $reference,
            'order_external_id' => $reference,
            'attempts' => 1,
            'last_attempted_at' => '2026-11-01 12:00:00+00',
            ...$state,
        ]);
    }

    private function connect(): void
    {
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('test-refresh-token'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('test-access-token'), CarbonImmutable::now()->addHour());
    }
}
