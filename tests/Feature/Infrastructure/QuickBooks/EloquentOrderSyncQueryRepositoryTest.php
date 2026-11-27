<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\FailedOrderSyncDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Infrastructure\QuickBooks\EloquentOrderSyncQueryRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EloquentOrderSyncQueryRepository::class)]
final class EloquentOrderSyncQueryRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const string REALM = 'query-test-company';

    private OrderSyncQueryRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(OrderSyncQueryRepositoryInterface::class);
    }

    #[Test]
    public function it_pages_one_companys_failed_rows_newest_attempt_first(): void
    {
        $this->insertSync(890000011, ['last_attempted_at' => '2026-11-01 10:00:00+00']);
        $this->insertSync(890000012, ['last_attempted_at' => '2026-11-03 10:00:00+00']);
        $this->insertSync(890000013, ['last_attempted_at' => '2026-11-02 10:00:00+00']);
        $this->insertSync(890000014, ['realm_id' => 'another-company']);
        $this->insertSync(890000015, ['status' => 'pending']);
        $this->insertSync(890000016, ['status' => 'processing']);
        $this->insertSync(890000017, ['status' => 'synced']);

        $first = $this->repository->paginateFailed(self::REALM, PageRequest::from(1, 2));
        $second = $this->repository->paginateFailed(self::REALM, PageRequest::from(2, 2));

        self::assertSame([890000012, 890000013], self::references($first->items));
        self::assertSame([890000011], self::references($second->items));
        self::assertSame(3, $first->total);
        self::assertSame(2, $second->currentPage);
    }

    #[Test]
    public function a_row_whose_order_is_gone_has_no_placed_at(): void
    {
        $this->insertSync(890000011);

        $row = $this->repository->paginateFailed(self::REALM, PageRequest::from(1, 50))->items[0];

        self::assertNull($row->placedAt);
        self::assertNull($row->docType);
    }

    #[Test]
    public function an_unknown_stored_code_reads_as_outcome_unknown(): void
    {
        $this->insertSync(890000011, ['last_error_code' => 'a_code_from_another_release', 'last_error_message' => null]);

        $row = $this->repository->paginateFailed(self::REALM, PageRequest::from(1, 50))->items[0];

        self::assertSame(SyncReasonCode::OutcomeUnknown, $row->reason);
        self::assertSame(SyncReasonCode::OutcomeUnknown->message(), $row->message);
    }

    #[Test]
    public function it_reads_one_companys_row_states_by_reference(): void
    {
        $token = '00000000-0000-4000-8000-000000000001';
        $this->insertSync(890000021, ['status' => 'processing', 'claim_token' => $token, 'lease_expires_at' => DB::raw("now() + interval '1 minute'")]);
        $this->insertSync(890000022, ['status' => 'processing', 'claim_token' => $token, 'lease_expires_at' => DB::raw('now()')]);
        $this->insertSync(890000023, ['status' => 'pending']);
        $this->insertSync(890000024, ['status' => 'failed', 'doc_type' => 'sales_receipt', 'last_error_code' => 'item_missing']);
        $this->insertSync(890000025, ['status' => 'synced', 'doc_type' => 'invoice', 'qbo_doc_number' => 'DOC-9', 'qbo_txn_id' => 'txn-9']);
        $this->insertSync(890000026, ['realm_id' => 'another-company']);
        $this->insertSync(890000028);

        $this->travel(1)->years();
        $states = $this->repository->statesFor(self::REALM, [890000021, 890000022, 890000023, 890000024, 890000025, 890000026, 890000027]);
        $this->travelBack();

        \ksort($states);
        self::assertEquals([
            890000021 => new OrderSyncStateDTO(OrderSyncRowStatus::Processing, true, null, null, null, SyncReasonCode::QboValidation),
            890000022 => new OrderSyncStateDTO(OrderSyncRowStatus::Processing, false, null, null, null, SyncReasonCode::QboValidation),
            890000023 => new OrderSyncStateDTO(OrderSyncRowStatus::Pending, false, null, null, null, SyncReasonCode::QboValidation),
            890000024 => new OrderSyncStateDTO(OrderSyncRowStatus::Failed, false, DocType::SalesReceipt, null, null, SyncReasonCode::ItemMissing),
            890000025 => new OrderSyncStateDTO(OrderSyncRowStatus::Synced, false, DocType::Invoice, 'DOC-9', 'txn-9', SyncReasonCode::QboValidation),
        ], $states);
        self::assertSame([], $this->repository->statesFor(self::REALM, []));
    }

    /**
     * @param array<int, FailedOrderSyncDTO> $rows
     *
     * @return list<int>
     */
    private static function references(array $rows): array
    {
        return \array_values(\array_map(static fn(FailedOrderSyncDTO $row): int => $row->reference, $rows));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function insertSync(int $reference, array $state = []): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => $reference,
            'order_external_id' => $reference,
            'status' => 'failed',
            'attempts' => 1,
            'last_attempted_at' => '2026-11-01 12:00:00+00',
            'last_error_code' => 'qbo_validation',
            ...$state,
        ]);
    }
}
