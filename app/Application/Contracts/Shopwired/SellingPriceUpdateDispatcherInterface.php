<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\ValueObjects\IntId;

/**
 * Dispatch a queued selling-price update for one product's SKUs.
 *
 * Application fans bulk selling-price work out through this contract without
 * knowing the delivery mechanism (queue, inline, etc.).
 */
interface SellingPriceUpdateDispatcherInterface
{
    /**
     * @param non-empty-list<UpdatePriceCommand> $commands
     */
    public function dispatchSellingPriceBatch(IntId $productId, array $commands): void;
}
