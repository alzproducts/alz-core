<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Listeners;

use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Infrastructure\Jobs\Operations\RecordPricePeriodJob;

/**
 * Dispatches a queued job to record SCD2 price period when a SKU's pricing changes.
 *
 * Thin listener (like a controller): receives the domain event and dispatches
 * the job for async processing. All retry/failure handling lives in the job
 * via HandleDatabaseExceptions middleware.
 */
final class RecordPricePeriodListener
{
    public function handle(SkuRetailPricingUpdatedEvent $event): void
    {
        RecordPricePeriodJob::dispatch($event->sku, $event->newPrices);
    }
}
