<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Dispatchers;

use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Linnworks\UpdateLinnworksSaleStateJob;
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
    public function dispatchAddToSale(IntId $productId, SaleSettings $saleSettings, int $saleCategoryId): void
    {
        UpdateShopwiredAddToSaleJob::dispatch($productId, $saleSettings, $saleCategoryId);
    }

    #[Override]
    public function dispatchRemoveFromSale(IntId $productId, int $saleCategoryId): void
    {
        UpdateShopwiredRemoveFromSaleJob::dispatch($productId, $saleCategoryId);
    }

    #[Override]
    public function dispatchUpdateSaleState(IntId $productId, Sku $sku): void
    {
        UpdateLinnworksSaleStateJob::dispatch($productId, $sku);
    }

    #[Override]
    public function dispatchReconciliation(IntId $productId, ?SaleSettings $saleSettings): void
    {
        ReconcileProductSaleStateJob::dispatch($productId, $saleSettings)
            ->delay(\now()->addSeconds(self::RECONCILIATION_DELAY_SECONDS));
    }
}
