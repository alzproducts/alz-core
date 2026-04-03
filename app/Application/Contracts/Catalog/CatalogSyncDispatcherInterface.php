<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\ValueObjects\IntId;

interface CatalogSyncDispatcherInterface
{
    /**
     * Dispatch a job to update rating filter values for a single product.
     *
     * @param list<RatingFilterValue>|null $values Filter values to set, or null to remove
     */
    public function dispatchRatingFilterUpdate(IntId $productId, int $optionNo, ?array $values): void;
}
