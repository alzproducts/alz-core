<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;

/**
 * Command to update one or more inventory fields for a single SKU.
 */
final readonly class UpdateInventoryFieldsCommand
{
    /** @param list<InventoryFieldUpdate> $updates */
    public function __construct(
        public Sku $sku,
        public array $updates,
    ) {}
}
