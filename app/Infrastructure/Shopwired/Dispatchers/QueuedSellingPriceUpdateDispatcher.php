<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Dispatchers;

use App\Application\Contracts\Shopwired\SellingPriceUpdateDispatcherInterface;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Shopwired\UpdateSellingPriceBatchJob;
use Override;

/**
 * Queue-backed dispatcher for bulk selling-price updates.
 *
 * Translates an Application-layer dispatch intent into one queued job per product.
 */
final readonly class QueuedSellingPriceUpdateDispatcher implements SellingPriceUpdateDispatcherInterface
{
    /**
     * @param non-empty-list<UpdatePriceCommand> $commands
     */
    #[Override]
    public function dispatchSellingPriceBatch(IntId $productId, array $commands): void
    {
        UpdateSellingPriceBatchJob::dispatch($productId, $commands);
    }
}
