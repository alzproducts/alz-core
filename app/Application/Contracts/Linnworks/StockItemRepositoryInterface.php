<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\ValueObjects\Guid;

/**
 * Repository for Linnworks stock item persistence.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * @extends RepositoryWriteInterface<StockItemFull>
 */
interface StockItemRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Bulk-update is_archived and is_logically_deleted flags.
     *
     * Targeted two-pass update per flag:
     * 1. SET flag = true WHERE stock_item_id IN (:flagged_ids)
     * 2. SET flag = false WHERE stock_item_id NOT IN (:flagged_ids) AND flag = true
     *
     * Only touches rows that actually changed. Both flags updated atomically
     * in a single transaction.
     *
     * @param list<Guid> $archivedIds  Linnworks GUIDs of currently archived items
     * @param list<Guid> $deletedIds   Linnworks GUIDs of currently logically-deleted items
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function syncArchivedFlags(array $archivedIds, array $deletedIds): void;

    /**
     * Bulk upsert archived stock items by stock_item_id.
     *
     * Unlike the regular save() path, this method does NOT touch the
     * `stock_item_extended_properties` or `stock_item_suppliers` child
     * tables — historical extended-property and supplier rows are preserved
     * for items transitioning from active to archived state. Every upserted
     * row has `is_archived` and `is_logically_deleted` set to `true` — the
     * caller is expected to pass only rows the Linnworks `IsArchived = 1`
     * filter has already selected.
     *
     * @param list<StockItemFull> $items
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertArchivedStockItems(array $items): SaveManyResult;

    /**
     * Bulk-update is_composite flag via two-pass sync.
     *
     * 1. SET is_composite = true WHERE stock_item_id IN (:composite_ids)
     * 2. SET is_composite = false WHERE stock_item_id NOT IN (:composite_ids) AND is_composite = true
     *
     * @param list<Guid> $compositeIds Linnworks GUIDs of composite parent items
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function syncCompositeFlags(array $compositeIds): void;

    /**
     * Update inventory fields on the local stock item row identified by SKU.
     *
     * Called after a successful Linnworks write to keep the local mirror in sync.
     * Returns the number of affected rows (0 if the SKU is not found locally).
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function updateInventoryFieldsBySku(Sku $sku, InventoryFieldUpdate ...$updates): int;
}
