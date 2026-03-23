<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Listeners;

use App\Application\Shopwired\SaleManagement\Services\SaleStateDetectionService;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queue delivery mechanism for sale state detection.
 *
 * Delegates to SaleStateDetectionService (Application layer) which
 * inspects SkuPriceChange transitions and dispatches sale events.
 */
final class DetectSaleStateChangeListener implements ShouldQueue
{
    public function __construct(
        private readonly SaleStateDetectionService $saleStateDetection,
    ) {}

    public function handle(ProductPricingUpdatedEvent $event): void
    {
        $this->saleStateDetection->detectAndDispatch($event);
    }
}
