<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryInterface;
use App\Domain\Inventory\ValueObjects\StockItem;

/**
 * Repository for Linnworks stock item persistence.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * @extends RepositoryInterface<StockItem>
 */
interface StockItemRepositoryInterface extends RepositoryInterface {}
