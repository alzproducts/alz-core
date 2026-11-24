<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\Shared\ValueObjects\Deadline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\SpyOrderSyncDispatcher;
use Tests\TestCase;

/**
 * The real chunk use case, over rows.
 */
#[CoversNothing]
final class OrderChunkRunTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;

    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private FakeQuickBooksOrderPosting $posting;

    private SpyOrderSyncDispatcher $dispatcher;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting = new FakeQuickBooksOrderPosting());
        $this->app->instance(OrderSyncDispatcherInterface::class, $this->dispatcher = new SpyOrderSyncDispatcher());
        $this->connect();
    }

    #[Test]
    public function a_failed_row_is_not_attempted_without_force_retry(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertOrder(self::OTHER_REFERENCE);
        $this->insertSync(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'item_missing']);

        $this->runChunk(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false));

        self::assertSame(['A' . self::OTHER_REFERENCE], $this->postedDocNumbers());
        self::assertSame('failed', $this->rowStatus(self::REFERENCE));
        self::assertSame('synced', $this->rowStatus(self::OTHER_REFERENCE));
    }

    #[Test]
    public function a_failed_row_is_attempted_with_force_retry(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'item_missing']);

        $this->runChunk(self::chunkRun([self::REFERENCE], true));

        self::assertSame(['A' . self::REFERENCE], $this->postedDocNumbers());
        self::assertSame('synced', $this->rowStatus(self::REFERENCE));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function forceRetries(): iterable
    {
        yield 'without force_retry' => [false];
        yield 'with force_retry' => [true];
    }

    #[Test]
    #[DataProvider('forceRetries')]
    public function a_synced_row_is_never_posted_again(bool $forceRetry): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->runChunk(self::chunkRun([self::REFERENCE], false));
        $this->posting->documents = [];

        $this->runChunk(self::chunkRun([self::REFERENCE], $forceRetry));

        self::assertSame([], $this->postedDocNumbers());
        self::assertSame('synced', $this->rowStatus(self::REFERENCE));
    }

    #[Test]
    #[DataProvider('forceRetries')]
    public function a_re_attempt_calls_quickbooks_for_nothing_the_run_recorded_failed(bool $forceRetry): void
    {
        $this->insertOrder(self::REFERENCE, sku: 'SKU-MISSING');
        $this->insertOrder(self::OTHER_REFERENCE);
        $this->posting->missingSkus = ['SKU-MISSING'];
        $this->posting->throws['customerQuery'] = new QuickBooksDeadlineExceededException(0.5);

        $this->runChunk(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], $forceRetry));

        self::assertSame('failed', $this->rowStatus(self::REFERENCE));
        self::assertEquals([self::chunkRun([self::OTHER_REFERENCE], $forceRetry, attempt: 2)], $this->dispatcher->chunks);

        $this->posting->missingSkus = [];
        $this->posting->throws = [];
        $this->posting->lookedUp = [];
        $this->runChunk($this->dispatcher->chunks[0]);

        self::assertSame(['A' . self::OTHER_REFERENCE], $this->postedDocNumbers());
        self::assertNotContains('A' . self::REFERENCE, $this->posting->lookedUp);
        self::assertSame('failed', $this->rowStatus(self::REFERENCE));
        self::assertSame('synced', $this->rowStatus(self::OTHER_REFERENCE));
    }

    #[Test]
    public function the_run_id_and_trigger_reach_every_recorded_error(): void
    {
        $this->insertOrder(self::REFERENCE, sku: 'SKU-MISSING');
        $this->insertOrder(self::OTHER_REFERENCE, sku: 'SKU-MISSING');
        $this->posting->missingSkus = ['SKU-MISSING'];

        $this->runChunk(self::chunkRun([self::REFERENCE, self::OTHER_REFERENCE], false));

        self::assertSame(
            [['trigger' => 'range', 'run_id' => self::RUN_ID], ['trigger' => 'range', 'run_id' => self::RUN_ID]],
            DB::table('quickbooks.order_sync_errors')
                ->get(['trigger', 'run_id'])->map(static fn(object $row): array => (array) $row)->all(),
        );
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

    private function rowStatus(int $reference): mixed
    {
        return DB::table('quickbooks.order_syncs')->where('reference', $reference)->value('status');
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
