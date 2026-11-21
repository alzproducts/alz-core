<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncModel;
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
        SQL;

    public function __construct(
        private EloquentGateway $eloquentGateway,
        private DatabaseGateway $databaseGateway,
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

        $claimed = $this->databaseGateway->runSql(
            self::CLAIM_SQL,
            [$realmId, $reference, $orderExternalId, $token, $forceRetry ? 'true' : 'false'],
        );
        if ($claimed === 0) {
            return null;
        }

        $model = $this->eloquentGateway->firstWhereAll(
            OrderSyncModel::class,
            ['realm_id' => $realmId, 'reference' => $reference, 'claim_token' => $token],
        );

        return $model === null ? null : new OrderSyncClaimDTO($model->id, $token, $model->attempts);
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
            'status' => 'synced',
            'doc_type' => $posted->docType,
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
    public function recordFailed(string $orderSyncId, string $claimToken, string $errorCode, string $errorMessage): bool
    {
        return $this->updateClaimed($orderSyncId, $claimToken, [
            'status' => 'failed',
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
        ]);
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
