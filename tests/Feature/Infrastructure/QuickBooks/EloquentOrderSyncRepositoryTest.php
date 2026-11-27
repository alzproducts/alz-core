<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimRulesDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncFailureDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Infrastructure\QuickBooks\EloquentOrderSyncRepository;
use DateTimeImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    private const string TOKEN = '00000000-0000-4000-8000-000000000003';

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

        self::assertNull($this->repository->claim(self::REALM, self::REFERENCE, self::EXTERNAL_ID, new OrderSyncClaimRulesDTO($forceRetry, false, false)));
        self::assertEquals($before, self::row());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rowsHoldingAnUnknownOutcome(): iterable
    {
        yield 'failed' => [['status' => 'failed']];
        yield 'processing with an expired lease' => [self::lapsed()];
    }

    /**
     * @param array<string, mixed> $state
     */
    #[Test]
    #[DataProvider('rowsHoldingAnUnknownOutcome')]
    public function a_forced_re_attempt_claims_no_row_holding_an_unknown_outcome(array $state): void
    {
        $this->seedRow([...$state, 'attempts' => 1, 'last_error_code' => 'outcome_unknown', 'last_error_message' => 'Timed out']);
        $before = self::row();

        self::assertNull($this->repository->claim(self::REALM, self::REFERENCE, self::EXTERNAL_ID, new OrderSyncClaimRulesDTO(true, true, false)));
        self::assertEquals($before, self::row());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rowsAReAttemptStillClaims(): iterable
    {
        yield 'pending, never failed' => [['status' => 'pending']];
        yield 'processing with an expired lease, never failed' => [self::lapsed()];
        yield 'failed with another reason' => [['status' => 'failed', 'last_error_code' => 'qbo_unavailable']];
    }

    /**
     * @param array<string, mixed> $state
     */
    #[Test]
    #[DataProvider('rowsAReAttemptStillClaims')]
    public function a_forced_re_attempt_still_claims_a_row_without_an_unknown_outcome(array $state): void
    {
        $this->seedRow([...$state, 'attempts' => 1]);

        self::assertSame(2, $this->claim(forceRetry: true, refuseUnknownOutcome: true)->attempts);
        self::assertSame('processing', self::row()->status);
    }

    #[Test]
    public function an_unforced_claim_that_retries_a_missing_item_claims_a_row_failed_for_one(): void
    {
        $this->seedRow(['status' => 'failed', 'attempts' => 1, 'last_error_code' => 'item_missing']);

        self::assertSame(2, $this->claim(retryItemMissing: true)->attempts);
        self::assertSame('processing', self::row()->status);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function failedRowsAnUnforcedClaimRefuses(): iterable
    {
        yield 'item missing, without the retry rule' => ['item_missing', false];
        yield 'validation failure, with the retry rule' => ['qbo_validation', true];
    }

    #[Test]
    #[DataProvider('failedRowsAnUnforcedClaimRefuses')]
    public function an_unforced_claim_takes_no_other_failed_row(string $lastErrorCode, bool $retryItemMissing): void
    {
        $this->seedRow(['status' => 'failed', 'attempts' => 1, 'last_error_code' => $lastErrorCode]);
        $before = self::row();

        self::assertNull($this->repository->claim(self::REALM, self::REFERENCE, self::EXTERNAL_ID, new OrderSyncClaimRulesDTO(false, false, $retryItemMissing)));
        self::assertEquals($before, self::row());
    }

    #[Test]
    public function record_synced_with_the_live_token_stores_the_outcome_and_releases_the_lease(): void
    {
        $claim = $this->claim();

        self::assertTrue($this->repository->recordSynced($claim->orderSyncId, $claim->claimToken, new OrderSyncPostedDTO(DocType::SalesReceipt, 'txn-9', 'DOC-9', 'hash-9')));

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

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function claimableRowsWithAReason(): iterable
    {
        yield 'failed, with force' => [['status' => 'failed'], true];
        yield 'pending' => [['status' => 'pending'], false];
        yield 'lapsed lease' => [self::lapsed(), false];
    }

    /**
     * @param array<string, mixed> $state
     */
    #[Test]
    #[DataProvider('claimableRowsWithAReason')]
    public function one_statement_claims_the_row_and_returns_its_lease_and_stored_reason(array $state, bool $forceRetry): void
    {
        $this->seedRow([...$state, 'attempts' => 4, 'last_error_code' => 'outcome_unknown', 'last_error_message' => 'Timed out']);
        DB::enableQueryLog();

        $claim = $this->claim($forceRetry);

        self::assertCount(1, DB::getQueryLog());
        $row = self::row();
        self::assertSame($row->id, $claim->orderSyncId);
        self::assertSame($row->claim_token, $claim->claimToken);
        self::assertTrue(self::leaseMinutesLeft() > 14.0);
        self::assertEquals(new DateTimeImmutable($row->lease_expires_at)->format('U'), $claim->leaseExpiresAt->format('U'));
        self::assertSame(SyncReasonCode::OutcomeUnknown, $claim->storedCode);
        self::assertSame([5, 5], [$claim->attempts, $row->attempts]);
        self::assertSame(['outcome_unknown', 'Timed out'], [$row->last_error_code, $row->last_error_message]);
    }

    #[Test]
    public function a_claim_without_a_known_stored_reason_carries_none(): void
    {
        self::assertNull($this->claim()->storedCode);

        DB::table('quickbooks.order_syncs')->where('realm_id', self::REALM)->update(['status' => 'failed', 'last_error_code' => 'a_code_from_another_release']);
        self::assertNull($this->claim(forceRetry: true)->storedCode);
    }

    #[Test]
    public function release_returns_a_reclaimed_lapsed_row_with_a_reason_to_failed(): void
    {
        $this->seedRow([...self::lapsed(), 'attempts' => 3, 'last_error_code' => 'qbo_unavailable', 'last_error_message' => 'Down']);
        $claim = $this->claim();

        self::assertTrue($this->repository->release($claim->orderSyncId, $claim->claimToken));

        $row = self::row();
        self::assertSame(['failed', 4, 'qbo_unavailable', 'Down'], [$row->status, $row->attempts, $row->last_error_code, $row->last_error_message]);
        self::assertNull($row->claim_token);
        self::assertNull($row->lease_expires_at);
        self::assertSame([], self::errors());
    }

    #[Test]
    public function release_returns_a_row_without_a_reason_to_pending(): void
    {
        $claim = $this->claim();

        self::assertTrue($this->repository->release($claim->orderSyncId, $claim->claimToken));

        $row = self::row();
        self::assertSame(['pending', 1, null, null], [$row->status, $row->attempts, $row->last_error_code, $row->last_error_message]);
        self::assertNull($row->claim_token);
        self::assertNull($row->lease_expires_at);
        self::assertSame([], self::errors());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreleasableRows(): iterable
    {
        yield 'processing, another token' => ['processing', '00000000-0000-4000-8000-000000000009'];
        yield 'pending' => ['pending', self::TOKEN];
        yield 'synced' => ['synced', self::TOKEN];
        yield 'failed' => ['failed', self::TOKEN];
    }

    #[Test]
    #[DataProvider('unreleasableRows')]
    public function release_changes_no_row_the_caller_does_not_hold(string $status, string $token): void
    {
        $this->seedRow(['status' => $status, 'attempts' => 2, 'claim_token' => self::TOKEN, 'lease_expires_at' => '2099-01-01 00:00:00+00', 'last_error_code' => 'item_missing']);
        $before = self::row();

        self::assertFalse($this->repository->release($before->id, $token));

        self::assertEquals($before, self::row());
        self::assertSame([], self::errors());
    }

    /**
     * @return iterable<string, array{DocType|null}>
     */
    public static function docTypes(): iterable
    {
        yield 'no doc type' => [null];
        yield 'invoice' => [DocType::Invoice];
    }

    #[Test]
    #[DataProvider('docTypes')]
    public function record_failed_stores_the_failure_and_appends_one_error_row(?DocType $docType): void
    {
        $this->seedRow([...self::lapsed(), 'doc_type' => 'sales_receipt']);
        $claim = $this->claim();
        $transactions = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$transactions): void {
            ++$transactions;
        });

        self::assertTrue($this->repository->recordFailed($claim->orderSyncId, $claim->claimToken, self::failure($docType)));
        // A nested transaction turns a deadlock into a logged message that holds the bound values.
        self::assertSame(1, $transactions);

        $row = self::row();
        self::assertSame(['failed', 'outcome_unknown', 'Timed out', $docType?->value], [$row->status, $row->last_error_code, $row->last_error_message, $row->doc_type]);
        self::assertNull($row->claim_token);
        self::assertNull($row->lease_expires_at);

        $errors = self::errors();
        self::assertCount(1, $errors);
        $error = $errors[0];
        self::assertSame(
            [$claim->orderSyncId, 'item_missing', 'range', 'run-7', '6000', 'Line.Amount', 'Timed out'],
            [$error->order_sync_id, $error->reason_code, $error->trigger, $error->run_id, $error->qbo_fault_code, $error->qbo_fault_element, $error->message],
        );
        self::assertNotNull($error->created_at);
    }

    #[Test]
    public function record_failed_without_a_run_or_fault_stores_neither(): void
    {
        $claim = $this->claim();

        self::assertTrue($this->repository->recordFailed($claim->orderSyncId, $claim->claimToken, new OrderSyncFailureDTO(
            SyncReasonCode::ZeroLines,
            SyncReasonCode::ZeroLines,
            'No lines',
            null,
            null,
            OrderSyncTrigger::Manual,
            null,
        )));

        $error = self::errors()[0];
        self::assertSame(['manual', null, null, null], [$error->trigger, $error->run_id, $error->qbo_fault_code, $error->qbo_fault_element]);
    }

    #[Test]
    public function record_failed_leaves_the_row_when_the_error_insert_fails_and_keeps_the_message_out_of_the_exception(): void
    {
        $claim = $this->claim();
        $before = self::row();

        $thrown = null;
        try {
            // "\xFF" is not valid UTF-8, so Postgres rejects the error row's run id.
            $this->repository->recordFailed($claim->orderSyncId, $claim->claimToken, self::failure(DocType::Invoice, "\xFF"));
        } catch (DatabaseOperationFailedException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertEquals($before, self::row());
        self::assertSame([], self::errors());
        self::assertNull($thrown->getPrevious());
        self::assertStringNotContainsString('Timed out', $thrown->reason . $thrown->getMessage());
    }

    #[Test]
    public function record_calls_with_a_stale_token_change_no_row(): void
    {
        $first = $this->claim();
        DB::table('quickbooks.order_syncs')->where('id', $first->orderSyncId)->update(['lease_expires_at' => DB::raw("now() - interval '1 minute'")]);
        $second = $this->claim();
        $before = self::row();

        self::assertFalse($this->repository->recordSynced($first->orderSyncId, $first->claimToken, new OrderSyncPostedDTO(DocType::Invoice, 'txn-1', null, null)));
        self::assertFalse($this->repository->recordFailed($first->orderSyncId, $first->claimToken, self::failure()));
        self::assertFalse($this->repository->release($first->orderSyncId, $first->claimToken));
        self::assertSame([], self::errors());

        self::assertEquals($before, self::row());
        self::assertSame($second->claimToken, self::row()->claim_token);
    }

    #[Test]
    public function a_claim_is_scoped_to_its_realm(): void
    {
        $this->seedRow(['status' => 'synced']);

        $other = $this->repository->claim('realm-repository-other', self::REFERENCE, self::EXTERNAL_ID, new OrderSyncClaimRulesDTO(false, false, false));

        self::assertNotNull($other);
        self::assertSame('synced', self::row()->status);
    }

    private static function failure(?DocType $docType = null, string $runId = 'run-7'): OrderSyncFailureDTO
    {
        return new OrderSyncFailureDTO(
            SyncReasonCode::OutcomeUnknown,
            SyncReasonCode::ItemMissing,
            'Timed out',
            $docType,
            new QuickBooksFault(QuickBooksFaultKind::Validation, '6000', 'Line.Amount', 'Amount is invalid'),
            OrderSyncTrigger::Range,
            $runId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function lapsed(): array
    {
        return ['status' => 'processing', 'claim_token' => self::TOKEN, 'lease_expires_at' => '2026-01-01 00:00:00+00'];
    }

    /**
     * @return list<stdClass>
     */
    private static function errors(): array
    {
        return \array_values(DB::table('quickbooks.order_sync_errors')->where('order_sync_id', self::row()->id)->get()->all());
    }

    private function claim(bool $forceRetry = false, bool $refuseUnknownOutcome = false, bool $retryItemMissing = false): OrderSyncClaimDTO
    {
        $claim = $this->repository->claim(self::REALM, self::REFERENCE, self::EXTERNAL_ID, new OrderSyncClaimRulesDTO($forceRetry, $refuseUnknownOutcome, $retryItemMissing));
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
