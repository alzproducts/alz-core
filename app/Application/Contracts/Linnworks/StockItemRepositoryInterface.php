<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Linnworks\DTOs\ArchivedStockItemDTO;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
     * Bulk upsert archived/logically-deleted stock items by stock_item_id.
     *
     * Unlike the regular save() path, this method does NOT touch the
     * `stock_item_extended_properties` or `stock_item_suppliers` child
     * tables — historical extended-property and supplier rows are preserved
     * for items transitioning from active to archived state. The upsert
     * writes `is_archived` and `is_logically_deleted` directly from each
     * incoming DTO.
     *
     * @param list<ArchivedStockItemDTO> $records
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertArchivedStockItems(array $records): SaveManyResult;
}
