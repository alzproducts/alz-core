<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Per-realm sync state for one order reference, claimed under a 15-minute lease.
 * Record calls change the row only while the caller's claim token still matches.
 */
interface OrderSyncRepositoryInterface
{
    /**
     * Claims a `pending` row, a `processing` row whose lease has expired, or a `failed` row when
     * `$forceRetry` is set, creating the row when the reference has none. Null means not claimed.
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
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function recordFailed(string $orderSyncId, string $claimToken, string $errorCode, string $errorMessage): bool;
}
