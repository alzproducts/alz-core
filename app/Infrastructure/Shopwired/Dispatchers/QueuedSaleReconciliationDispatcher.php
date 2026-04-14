<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Dispatchers;

use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Shopwired\ReconcileProductSaleStateJob;
use App\Infrastructure\Jobs\Shopwired\UpdateShopwiredAddToSaleJob;
use App\Infrastructure\Jobs\Shopwired\UpdateShopwiredRemoveFromSaleJob;
use Override;

/**
 * Queue-backed dispatcher for sale state reconciliation.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedSaleReconciliationDispatcher implements SaleReconciliationDispatcherInterface
{
    private const int RECONCILIATION_DELAY_SECONDS = 300;

    #[Override]
    public function dispatchAddToSale(IntId $productId): void
    {
        UpdateShopwiredAddToSaleJob::dispatch($productId);
    }

    #[Override]
    public function dispatchRemoveFromSale(IntId $productId): void
    {
        UpdateShopwiredRemoveFromSaleJob::dispatch($productId);
    }

    #[Override]
    public function dispatchReconciliation(IntId $productId): void
    {
        ReconcileProductSaleStateJob::dispatch($productId)
            ->delay(\now()->addSeconds(self::RECONCILIATION_DELAY_SECONDS));
    }
}
