<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Inventory\ValueObjects\StockItemFull;

/**
 * Repository for Linnworks stock item persistence.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * @extends RepositoryWriteInterface<StockItemFull>
 */
interface StockItemRepositoryInterface extends RepositoryWriteInterface {}
