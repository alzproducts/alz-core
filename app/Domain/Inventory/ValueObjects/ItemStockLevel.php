<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use Webmozart\Assert\Assert;

/**
 * Stock level update request for a single item.
 *
 * Minimal value object for stock quantity updates. Contains only
 * the SKU identifier and target quantity - no other inventory details.
 *
 * Use for: ShopWired stock updates, batch inventory operations
 * Not for: Full inventory queries (use StockItem instead)
 *
 * @template-pattern Domain Value Object
 */
final readonly class ItemStockLevel
{
    public function __construct(
        public Sku $sku,
        public int $quantity,
    ) {
        Assert::greaterThanEq($quantity, 0, 'Quantity cannot be negative');
    }
}
