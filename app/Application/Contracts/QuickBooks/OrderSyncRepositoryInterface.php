<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncFailureDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Per-realm sync state for one order reference, claimed under a 15-minute lease.
 * Record and release calls change the row only while the caller's claim token still matches; they
 * return false, with nothing written, when it does not.
 */
interface OrderSyncRepositoryInterface
{
    /**
     * Claims a `pending` row, a `processing` row whose lease has expired, or a `failed` row when
     * `$forceRetry` is set, creating the row when the reference has none. The stored reason stays on
     * the row. Null means not claimed.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function claim(string $realmId, int $reference, int $orderExternalId, bool $forceRetry): ?OrderSyncClaimDTO;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function recordSynced(string $orderSyncId, string $claimToken, OrderSyncPostedDTO $posted): bool;

    /**
     * Fails the row and appends its error row, both or neither. A thrown failure carries no bound value.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function recordFailed(string $orderSyncId, string $claimToken, OrderSyncFailureDTO $failure): bool;

    /**
     * Returns the row to `failed` when it stores a failure reason, else to `pending`; attempts and reason stay.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function release(string $orderSyncId, string $claimToken): bool;
}
