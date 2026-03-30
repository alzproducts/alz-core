<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderCore;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderFull;

/**
 * Repository for Linnworks purchase order persistence.
 *
 * Two sync strategies:
 * - save(PurchaseOrderFull): Full sync — upserts parent + all 5 child tables
 *   with orphan deletion. Use for historical backfill.
 * - saveCore(PurchaseOrderCore): Partial sync — upserts parent + items +
 *   additional costs + delivered records only. Does NOT touch notes or
 *   extended properties (they weren't fetched, so their absence ≠ deletion).
 *   Use for rapid OPEN/PENDING polling.
 *
 * Each save is wrapped in a transaction for atomicity.
 *
 * @extends RepositoryWriteInterface<PurchaseOrderFull>
 */
interface PurchaseOrderSyncRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Persist a complete purchase order with all child entities atomically.
     *
     * Upserts parent + items + additional costs + delivered records + notes
     * + extended properties. Orphan-deletes all child rows not in the current
     * payload.
     *
     * @param object $entity PurchaseOrderFull
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void;

    /**
     * Persist a purchase order core (single-call sync) without touching notes or EPs.
     *
     * Upserts parent + items + additional costs + delivered records.
     * Notes and extended properties are NOT modified — they may exist from a
     * prior full sync and should be preserved.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveCore(PurchaseOrderCore $purchaseOrder): void;
}
