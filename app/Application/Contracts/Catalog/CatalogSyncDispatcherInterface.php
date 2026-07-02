<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Enums\CustomLabelField;
use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\ValueObjects\IntId;
use BackedEnum;

interface CatalogSyncDispatcherInterface
{
    /**
     * Dispatch a job to set a custom-label field on a single product.
     *
     * @param string|null $value Label value, or null to clear the field
     */
    public function dispatchLabelUpdate(IntId $productId, CustomLabelField $field, ?string $value): void;

    /**
     * Dispatch a job to update product filter values for a single product.
     *
     * @param list<ShopwiredFilterValueInterface&BackedEnum>|null $values Filter values to set, or null to remove
     */
    public function dispatchFilterUpdate(IntId $productId, int $optionNo, ?array $values): void;

    /**
     * Dispatch a job to update the sort_order field on a single ShopWired product.
     */
    public function dispatchSortOrderUpdate(IntId $productId, int $sortOrder): void;
}
