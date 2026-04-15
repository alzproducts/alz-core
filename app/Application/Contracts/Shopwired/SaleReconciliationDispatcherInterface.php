<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\ValueObjects\IntId;

/**
 * Dispatcher for sale state reconciliation jobs.
 */
interface SaleReconciliationDispatcherInterface
{
    /**
     * Dispatch add-to-sale corrections for a product.
     *
     * SaleSettings are read from DB by the UseCase at execution time.
     */
    public function dispatchAddToSale(IntId $productId): void;

    /**
     * Dispatch remove-from-sale corrections for a product.
     */
    public function dispatchRemoveFromSale(IntId $productId): void;

    /**
     * Dispatch a delayed reconciliation check for a product.
     */
    public function dispatchReconciliation(IntId $productId): void;
}
