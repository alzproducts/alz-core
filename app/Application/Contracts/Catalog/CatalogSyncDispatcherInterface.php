<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\ValueObjects\IntId;
use BackedEnum;

interface CatalogSyncDispatcherInterface
{
    /**
     * Dispatch a job to update product filter values for a single product.
     *
     * @param list<ShopwiredFilterValueInterface&BackedEnum>|null $values Filter values to set, or null to remove
     */
    public function dispatchFilterUpdate(IntId $productId, int $optionNo, ?array $values): void;
}
