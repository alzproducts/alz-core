<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncFailureDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncErrorLogModel;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;
use Override;

/**
 * The claim is one conditional upsert, so concurrent claims on a reference serialise on its
 * unique key and exactly one wins. There is no sweeper: an expired lease is simply claimable.
 */
final readonly class EloquentOrderSyncRepository implements OrderSyncRepositoryInterface
{
    private const string CLAIM_SQL = <<<'SQL'
        INSERT INTO quickbooks.order_syncs AS s
            (realm_id, reference, order_external_id, status, attempts, last_attempted_at, claim_token, lease_expires_at)
        VALUES (?, ?, ?, 'processing', 1, now(), ?::uuid, now() + interval '15 minutes')
        ON CONFLICT (realm_id, reference) DO UPDATE SET
            order_external_id = EXCLUDED.order_external_id,
            status = 'processing',
            attempts = s.attempts + 1,
            last_attempted_at = now(),
            claim_token = EXCLUDED.claim_token,
            lease_expires_at = EXCLUDED.lease_expires_at,
            updated_at = now()
        WHERE s.status = 'pending'
           OR (s.status = 'processing' AND s.lease_expires_at <= now())
           OR (s.status = 'failed' AND ?::boolean)
        RETURNING s.*
        SQL;

    // intentional: the claim keeps the stored reason, so it tells a retried row from an untried one.
    private const string RELEASED_STATUS_SQL = "CASE WHEN last_error_code IS NULL THEN 'pending' ELSE 'failed' END";

    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function claim(string $realmId, int $reference, int $orderExternalId, bool $forceRetry): ?OrderSyncClaimDTO
    {
        $token = Str::uuid()->toString();

        /** @var Collection<int, OrderSyncModel> $rows */
        $rows = $this->eloquentGateway->query(static fn(): Collection => OrderSyncModel::query()->fromQuery(
            self::CLAIM_SQL,
            [$realmId, $reference, $orderExternalId, $token, $forceRetry ? 'true' : 'false'],
        ));
        $row = $rows->first();
        if ($row?->lease_expires_at === null) {
            return null;
        }

        return new OrderSyncClaimDTO($row->id, $token, $row->attempts, $row->lease_expires_at, SyncReasonCode::tryFrom($row->last_error_code ?? ''));
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function recordSynced(string $orderSyncId, string $claimToken, OrderSyncPostedDTO $posted): bool
    {
        return $this->updateClaimed($orderSyncId, $claimToken, [
            'status' => OrderSyncRowStatus::Synced->value,
            'doc_type' => $posted->docType->value,
            'qbo_txn_id' => $posted->qboTxnId,
            'qbo_doc_number' => $posted->qboDocNumber,
            'payload_hash' => $posted->payloadHash,
            'synced_at' => new Expression('now()'),
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function recordFailed(string $orderSyncId, string $claimToken, OrderSyncFailureDTO $failure): bool
    {
        // intentional: the chained exceptions hold the bound message, which can be customer data, so both rethrows drop them.
        try {
            return $this->eloquentGateway->transact(fn(): bool => $this->writeFailure($orderSyncId, $claimToken, $failure));
        } catch (DatabaseOperationFailedException $e) {
            throw self::withoutChain($e);
        } catch (ExternalServiceUnavailableException $e) {
            throw self::unavailableWithoutChain($e);
        }
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function release(string $orderSyncId, string $claimToken): bool
    {
        return $this->updateClaimed($orderSyncId, $claimToken, ['status' => new Expression(self::RELEASED_STATUS_SQL)]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function writeFailure(string $orderSyncId, string $claimToken, OrderSyncFailureDTO $failure): bool
    {
        $recorded = $this->updateClaimed($orderSyncId, $claimToken, [
            'status' => OrderSyncRowStatus::Failed->value,
            'doc_type' => $failure->docType?->value,
            'last_error_code' => $failure->code->value,
            'last_error_message' => $failure->message,
        ]);
        if (!$recorded) {
            return false;
        }

        return new OrderSyncErrorLogModel([
            'order_sync_id' => $orderSyncId,
            'trigger' => $failure->trigger->value,
            'run_id' => $failure->runId,
            'reason_code' => $failure->loggedCode->value,
            'qbo_fault_code' => $failure->fault?->code,
            'qbo_fault_element' => $failure->fault?->element,
            'message' => $failure->message,
        ])->save();
    }

    private static function withoutChain(DatabaseOperationFailedException $e): DatabaseOperationFailedException
    {
        return new DatabaseOperationFailedException($e->operation, 'Recording a failed order sync failed');
    }

    private static function unavailableWithoutChain(ExternalServiceUnavailableException $e): ExternalServiceUnavailableException
    {
        return new ExternalServiceUnavailableException($e->serviceName, $e->retryAfter);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function updateClaimed(string $orderSyncId, string $claimToken, array $data): bool
    {
        return $this->eloquentGateway->updateWhereAll(
            OrderSyncModel::class,
            ['id' => $orderSyncId, 'status' => 'processing', 'claim_token' => $claimToken],
            [...$data, 'claim_token' => null, 'lease_expires_at' => null],
        ) > 0;
    }
}
