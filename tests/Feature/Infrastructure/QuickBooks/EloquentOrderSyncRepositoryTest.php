<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Infrastructure\QuickBooks\EloquentOrderSyncRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(EloquentOrderSyncRepository::class)]
final class EloquentOrderSyncRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const string REALM = 'realm-repository-test';

    private const int REFERENCE = 2147450301;

    private const int EXTERNAL_ID = 2147450311;

    private OrderSyncRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(OrderSyncRepositoryInterface::class);
    }

    #[Test]
    public function a_first_claim_creates_a_processing_row_under_a_lease(): void
    {
        $claim = $this->claim();

        $row = self::row();
        self::assertSame($row->id, $claim->orderSyncId);
        self::assertSame($row->claim_token, $claim->claimToken);
        self::assertSame(1, $claim->attempts);
        self::assertSame('processing', $row->status);
        self::assertSame(self::EXTERNAL_ID, $row->order_external_id);
        self::assertTrue(self::leaseMinutesLeft() > 14.0 && self::leaseMinutesLeft() <= 15.0);
    }

    #[Test]
    public function a_pending_row_is_claimed_and_its_attempts_increment(): void
    {
        $this->seedRow(['status' => 'pending', 'attempts' => 2]);

        self::assertSame(3, $this->claim()->attempts);
        self::assertSame(3, self::row()->attempts);
    }

    #[Test]
    public function a_processing_row_with_an_expired_lease_is_claimed_again(): void
    {
        $stale = '00000000-0000-4000-8000-000000000001';
        $this->seedRow(['status' => 'processing', 'attempts' => 1, 'claim_token' => $stale, 'lease_expires_at' => DB::raw("now() - interval '1 minute'")]);

        $claim = $this->claim();

        self::assertNotSame($stale, $claim->claimToken);
        self::assertSame(2, $claim->attempts);
        self::assertSame($claim->claimToken, self::row()->claim_token);
    }

    #[Test]
    public function a_failed_row_is_claimed_with_force_retry(): void
    {
        $this->seedRow(['status' => 'failed', 'attempts' => 1, 'last_error_code' => 'qbo_fault']);

        $claim = $this->claim(forceRetry: true);

        self::assertSame(2, $claim->attempts);
        self::assertSame('processing', self::row()->status);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function unclaimableRows(): iterable
    {
        yield 'synced, without force' => [['status' => 'synced'], false];
        yield 'synced, with force' => [['status' => 'synced'], true];
        yield 'failed, without force' => [['status' => 'failed'], false];
        yield 'processing under a live lease, with force' => [
            ['status' => 'processing', 'claim_token' => '00000000-0000-4000-8000-000000000002', 'lease_expires_at' => '2099-01-01 00:00:00+00'],
            true,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    #[Test]
    #[DataProvider('unclaimableRows')]
    public function no_claim_is_returned_for_a_row_that_is_not_claimable(array $state, bool $forceRetry): void
    {
        $this->seedRow([...$state, 'attempts' => 1]);
        $before = self::row();

        self::assertNull($this->repository->claim(self::REALM, self::REFERENCE, self::EXTERNAL_ID, $forceRetry));
        self::assertEquals($before, self::row());
    }

    #[Test]
    public function record_synced_with_the_live_token_stores_the_outcome_and_releases_the_lease(): void
    {
        $claim = $this->claim();

        self::assertTrue($this->repository->recordSynced($claim->orderSyncId, $claim->claimToken, new OrderSyncPostedDTO('sales_receipt', 'txn-9', 'DOC-9', 'hash-9')));

        $row = self::row();
        self::assertSame('synced', $row->status);
        self::assertSame('sales_receipt', $row->doc_type);
        self::assertSame('txn-9', $row->qbo_txn_id);
        self::assertSame('DOC-9', $row->qbo_doc_number);
        self::assertSame('hash-9', $row->payload_hash);
        self::assertNotNull($row->synced_at);
        self::assertNull($row->claim_token);
        self::assertNull($row->lease_expires_at);
    }

    #[Test]
    public function record_failed_with_the_live_token_stores_the_error_and_releases_the_lease(): void
    {
        $claim = $this->claim();

        self::assertTrue($this->repository->recordFailed($claim->orderSyncId, $claim->claimToken, 'outcome_unknown', 'Timed out'));

        $row = self::row();
        self::assertSame('failed', $row->status);
        self::assertSame('outcome_unknown', $row->last_error_code);
        self::assertSame('Timed out', $row->last_error_message);
        self::assertNull($row->claim_token);
        self::assertNull($row->lease_expires_at);
    }

    #[Test]
    public function record_calls_with_a_stale_token_change_no_row(): void
    {
        $first = $this->claim();
        DB::table('quickbooks.order_syncs')->where('id', $first->orderSyncId)->update(['lease_expires_at' => DB::raw("now() - interval '1 minute'")]);
        $second = $this->claim();
        $before = self::row();

        self::assertFalse($this->repository->recordSynced($first->orderSyncId, $first->claimToken, new OrderSyncPostedDTO('invoice', 'txn-1', null, null)));
        self::assertFalse($this->repository->recordFailed($first->orderSyncId, $first->claimToken, 'qbo_fault', 'Rejected'));

        self::assertEquals($before, self::row());
        self::assertSame($second->claimToken, self::row()->claim_token);
    }

    #[Test]
    public function a_claim_is_scoped_to_its_realm(): void
    {
        $this->seedRow(['status' => 'synced']);

        $other = $this->repository->claim('realm-repository-other', self::REFERENCE, self::EXTERNAL_ID, false);

        self::assertNotNull($other);
        self::assertSame('synced', self::row()->status);
    }

    private function claim(bool $forceRetry = false): OrderSyncClaimDTO
    {
        $claim = $this->repository->claim(self::REALM, self::REFERENCE, self::EXTERNAL_ID, $forceRetry);
        self::assertNotNull($claim);

        return $claim;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function seedRow(array $state): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => self::REFERENCE,
            'order_external_id' => self::EXTERNAL_ID,
            ...$state,
        ]);
    }

    private static function row(): stdClass
    {
        $row = DB::table('quickbooks.order_syncs')->where('realm_id', self::REALM)->where('reference', self::REFERENCE)->sole();
        self::assertInstanceOf(stdClass::class, $row);

        return $row;
    }

    private static function leaseMinutesLeft(): float
    {
        $row = DB::selectOne(
            'SELECT EXTRACT(EPOCH FROM lease_expires_at - now()) / 60 AS minutes FROM quickbooks.order_syncs WHERE realm_id = ? AND reference = ?',
            [self::REALM, self::REFERENCE],
        );
        self::assertNotNull($row);

        return (float) $row->minutes;
    }
}
