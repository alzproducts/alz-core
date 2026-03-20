<?php

declare(strict_types=1);

namespace App\Application\Contracts\Inventory;

use App\Application\Enums\SyncCursorType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;

/**
 * Persistence contract for sync cursors.
 *
 * Tracks the last successful sync timestamp per sync type,
 * enabling incremental sync strategies.
 */
interface SyncCursorRepositoryInterface
{
    /**
     * Get the last successful sync datetime for the given type.
     *
     * Returns null if no sync has run yet.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getLastSyncDate(SyncCursorType $syncType): ?DateTimeImmutable;

    /**
     * Update the last successful sync datetime for the given type.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function updateLastSyncDate(SyncCursorType $syncType, DateTimeImmutable $date): void;
}
