<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\TestCase;
use Tests\Unit\Infrastructure\QuickBooks\RecordingLogger;

/**
 * Orders QuickBooks never received, through the real chunk use case and dispatcher. The port answers every unsent
 * failure (429 past its retries, network error, 5xx, limiter outage) with one exception class, as the transport tests
 * prove, so the providers name the failure each case stands for.
 */
#[CoversNothing]
final class UnsentOrderRetryTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;

    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private const int UNBUILDABLE_REFERENCE = 890000003;

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        \config(['quickbooks.backfill_floor_date' => '2026-01-01']);
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting = new FakeQuickBooksOrderPosting());
        $this->connect();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsentCalls(): iterable
    {
        foreach (['429' => '429', 'network error' => 'network', '5xx' => '503', 'limiter outage' => 'limiter'] as $failure => $name) {
            foreach (['DocNumber lookup' => 'findDocuments', 'item query' => 'findItems', 'customer query' => 'customerQuery', 'customer create' => 'customerCreate'] as $call => $step) {
                yield "{$failure} on the {$call}" => [$step, $name];
            }
        }
    }

    #[Test]
    #[DataProvider('unsentCalls')]
    public function an_unsent_call_before_the_batch_create_releases_the_order_for_attempt_2_after_600_seconds(string $step, string $failure): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->throws[$step] = self::unavailable($failure);

        $this->runChunk(self::chunkRun([self::REFERENCE], false));

        $this->assertRow(self::REFERENCE, ['status' => 'pending', 'last_error_code' => null]);
        $this->assertOnlyQueued(self::chunkRun([self::REFERENCE], false, attempt: 2));
        self::assertSame([], $this->posting->documents);
    }

    #[Test]
    public function the_re_attempt_log_names_the_reason_it_re_queues(): void
    {
        $logger = new RecordingLogger();
        $this->app->when(RunOrderChunkUseCase::class)->needs(LoggerInterface::class)->give(static fn(): LoggerInterface => $logger);
        $this->insertOrder(self::REFERENCE);
        $this->posting->throws['findDocuments'] = self::unavailable('503');

        $this->runChunk(self::chunkRun([self::REFERENCE], false));

        $reattempts = \array_values(\array_filter($logger->records, static fn(array $record): bool => $record[0] === 'Re-attempting QuickBooks order chunk'));
        self::assertSame([['run_id' => self::RUN_ID, 'references' => [self::REFERENCE], 'cause' => ['qbo_unavailable']]], \array_column($reattempts, 1));
    }

    #[Test]
    #[DataProvider('unsentCalls')]
    public function an_unsent_call_before_the_batch_create_on_attempt_6_fails_the_order(string $step, string $failure): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->throws[$step] = self::unavailable($failure);

        $this->runChunk(self::chunkRun([self::REFERENCE], false, attempt: 6));

        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'qbo_unavailable']);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_limiter_outage_before_the_first_batch_send_releases_only_that_batch(): void
    {
        $this->insertBatchAndUnbuildable();
        $this->posting->throws['createDocuments'] = self::unavailable('limiter');

        $this->runChunk(self::chunkRun(self::references(), false));

        $this->assertBatchReleasedFor(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false, attempt: 2));
    }

    #[Test]
    public function a_batch_still_rate_limited_after_its_retries_releases_only_that_batch(): void
    {
        $this->insertBatchAndUnbuildable();
        $this->rateLimitBatch();

        $this->runChunk(self::chunkRun(self::references(), false));

        $this->assertBatchReleasedFor(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false, attempt: 2));
    }

    #[Test]
    public function a_limiter_outage_before_the_first_batch_send_on_attempt_6_fails_the_batch(): void
    {
        $this->insertBatch();
        $this->posting->throws['createDocuments'] = self::unavailable('limiter');

        $this->runChunk(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false, attempt: 6));

        $this->assertBatchFailedUnavailable();
    }

    #[Test]
    public function a_batch_still_rate_limited_on_attempt_6_fails_the_batch(): void
    {
        $this->insertBatch();
        $this->rateLimitBatch();

        $this->runChunk(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false, attempt: 6));

        $this->assertBatchFailedUnavailable();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousBatchFailures(): iterable
    {
        yield 'network error' => ['network'];
        yield '5xx' => ['503'];
    }

    /**
     * The transport answers both with an ambiguous item for every document (`QuickBooksTransportBatchTest`).
     */
    #[Test]
    #[DataProvider('ambiguousBatchFailures')]
    public function a_batch_create_that_may_have_stored_its_documents_is_never_re_queued(string $failure): void
    {
        $this->insertBatch();
        $this->posting->batchOutcomes = [
            'A' . self::REFERENCE => BatchItemOutcomeResult::ambiguous(),
            'A' . self::OTHER_REFERENCE => BatchItemOutcomeResult::ambiguous(),
        ];

        $this->runChunk(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false));

        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        $this->assertRow(self::OTHER_REFERENCE, ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_network_error_before_the_batch_create_keeps_a_forced_unknown_outcome_failed(): void
    {
        $this->insertUnknownOutcome();
        $this->posting->throws['findItems'] = self::unavailable('network');

        $this->runChunk(self::chunkRun([self::REFERENCE], true));

        $this->assertUnknownOutcomeKept();
    }

    #[Test]
    public function a_limiter_outage_before_the_first_batch_send_keeps_a_forced_unknown_outcome_failed(): void
    {
        $this->insertUnknownOutcome();
        $this->posting->throws['createDocuments'] = self::unavailable('limiter');

        $this->runChunk(self::chunkRun([self::REFERENCE], true));

        $this->assertUnknownOutcomeKept();
    }

    #[Test]
    public function a_batch_still_rate_limited_keeps_a_forced_unknown_outcome_failed(): void
    {
        $this->insertUnknownOutcome();
        $this->posting->batchOutcomes['A' . self::REFERENCE] = BatchItemOutcomeResult::rateLimited(30);

        $this->runChunk(self::chunkRun([self::REFERENCE], true));

        $this->assertUnknownOutcomeKept();
    }

    #[Test]
    public function a_released_forced_order_keeps_its_earlier_reason_and_its_re_attempt_stays_forced(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'item_missing', 'last_error_message' => 'A line on the order has no SKU.']);
        $this->posting->throws['findItems'] = self::unavailable('503');

        $this->runChunk(self::chunkRun([self::REFERENCE], true));

        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'item_missing']);
        $reattempt = $this->assertOnlyQueued(self::chunkRun([self::REFERENCE], true, attempt: 2));
        self::assertTrue($reattempt->chunk->forceRetry);

        $this->posting->throws = [];
        $this->runChunk($reattempt);

        $this->assertRow(self::REFERENCE, ['status' => 'synced', 'qbo_txn_id' => 'txn-A' . self::REFERENCE]);
    }

    #[Test]
    public function a_re_attempt_that_finds_the_released_orders_document_does_not_send_it_again(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->throws['createDocuments'] = self::unavailable('limiter');
        $this->runChunk(self::chunkRun([self::REFERENCE], false));
        $reattempt = $this->assertOnlyQueued(self::chunkRun([self::REFERENCE], false, attempt: 2));

        $this->posting->throws = [];
        $this->posting->calls = [];
        $this->posting->existing = [new ExistingDocumentDTO('A' . self::REFERENCE, 'qbo-7', DocType::Invoice)];
        $this->runChunk($reattempt);

        self::assertSame([], $this->posting->documents);
        self::assertNotContains('createDocuments', $this->posting->calls);
        $this->assertRow(self::REFERENCE, ['status' => 'synced', 'qbo_txn_id' => 'qbo-7']);
    }

    /**
     * Finding the customer by name before creating it is `QuickBooksCustomerProvisionerTest`'s; this covers the re-attempt.
     */
    #[Test]
    public function a_re_attempt_after_a_customer_create_network_error_posts_against_the_customer(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->throws['customerCreate'] = self::unavailable('network');
        $this->runChunk(self::chunkRun([self::REFERENCE], false));
        $reattempt = $this->assertOnlyQueued(self::chunkRun([self::REFERENCE], false, attempt: 2));

        $this->posting->throws = [];
        $this->runChunk($reattempt);

        self::assertCount(1, $this->posting->documents);
        self::assertSame('customer-1', $this->posting->documents[0]->refs->customerId);
        $this->assertRow(self::REFERENCE, ['status' => 'synced', 'qbo_txn_id' => 'txn-A' . self::REFERENCE]);
    }

    private static function unavailable(string $failure): ExternalServiceUnavailableException
    {
        return new ExternalServiceUnavailableException('QuickBooks', $failure === '429' ? 30 : null);
    }

    private function runChunk(OrderChunkRunDTO $run): void
    {
        $this->app->make(RunOrderChunkUseCase::class)->execute($run, Deadline::after(CarbonImmutable::now()->toDateTimeImmutable(), 600));
    }

    /**
     * @param non-empty-list<int> $references
     */
    private static function chunkRun(array $references, bool $forceRetry, int $attempt = 1): OrderChunkRunDTO
    {
        return new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, $references, $forceRetry, OrderSyncTrigger::Range, self::RUN_ID), $attempt);
    }

    /**
     * @return non-empty-list<int>
     */
    private static function references(): array
    {
        return [self::REFERENCE, self::OTHER_REFERENCE, self::UNBUILDABLE_REFERENCE];
    }

    private function assertOnlyQueued(OrderChunkRunDTO $expected): OrderChunkRunDTO
    {
        $jobs = Queue::pushed(SyncQuickBooksOrderChunkJob::class)->values();
        self::assertCount(1, $jobs);
        $job = $jobs[0];
        self::assertInstanceOf(SyncQuickBooksOrderChunkJob::class, $job);
        self::assertEquals($expected, $job->run);
        self::assertSame(600, $job->delay);

        return $job->run;
    }

    private function insertBatch(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertOrder(self::OTHER_REFERENCE, state: ['billing_name' => 'Other Buyer', 'billing_email' => 'other@example.test']);
    }

    private function insertBatchAndUnbuildable(): void
    {
        $this->insertBatch();
        $this->insertOrder(self::UNBUILDABLE_REFERENCE, sku: 'SKU-MISSING');
        $this->posting->missingSkus = ['SKU-MISSING'];
    }

    private function rateLimitBatch(): void
    {
        $this->posting->batchOutcomes = [
            'A' . self::REFERENCE => BatchItemOutcomeResult::rateLimited(30),
            'A' . self::OTHER_REFERENCE => BatchItemOutcomeResult::rateLimited(30),
        ];
    }

    private function assertBatchReleasedFor(OrderChunkRunDTO $expected): void
    {
        $this->assertRow(self::REFERENCE, ['status' => 'pending', 'last_error_code' => null]);
        $this->assertRow(self::OTHER_REFERENCE, ['status' => 'pending', 'last_error_code' => null]);
        $this->assertRow(self::UNBUILDABLE_REFERENCE, ['status' => 'failed', 'last_error_code' => 'item_missing']);
        $this->assertOnlyQueued($expected);
    }

    private function assertBatchFailedUnavailable(): void
    {
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'qbo_unavailable']);
        $this->assertRow(self::OTHER_REFERENCE, ['status' => 'failed', 'last_error_code' => 'qbo_unavailable']);
        Queue::assertNothingPushed();
    }

    private function insertUnknownOutcome(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'outcome_unknown', 'last_error_message' => 'Timed out']);
    }

    private function assertUnknownOutcomeKept(): void
    {
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        self::assertStringContainsString('qbo_unavailable', (string) DB::table('quickbooks.order_syncs')->where('reference', self::REFERENCE)->value('last_error_message'));
        Queue::assertNothingPushed();
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
