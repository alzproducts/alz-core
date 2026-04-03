<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Dispatchers;

use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Catalog\UpdateProductRatingFilterJob;
use Override;

final readonly class QueuedCatalogSyncDispatcher implements CatalogSyncDispatcherInterface
{
    #[Override]
    public function dispatchRatingFilterUpdate(IntId $productId, int $optionNo, ?array $values): void
    {
        UpdateProductRatingFilterJob::dispatch(
            $productId,
            $optionNo,
            $values !== null ? RatingFilterValue::toStringArray($values) : null,
        );
    }
}
