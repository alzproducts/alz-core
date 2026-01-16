<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Inventory\ValueObjects\StockItem;

/**
 * Repository for Linnworks stock item persistence.
 *
 * Extends base Linnworks repository. Entity-specific sync notes:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * @extends LinnworksRepositoryInterface<StockItem>
 */
interface StockItemRepositoryInterface extends LinnworksRepositoryInterface
{
    // Entity-specific methods will be added here as needed.
    // Currently inherits saveMany() from LinnworksRepositoryInterface.
}
