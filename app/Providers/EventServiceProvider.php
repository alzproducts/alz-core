<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Infrastructure\Operations\Listeners\RecordPricePeriodListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Centralised event → listener wiring.
 *
 * Non-deferred so all listeners register eagerly on boot.
 * Keeps event wiring separate from service bindings, allowing
 * feature providers to remain deferred.
 *
 * NOTE: Some legacy event registrations still live in their feature providers
 * (InventoryServiceProvider, ContactSubmissionServiceProvider, NotificationServiceProvider).
 * New event wiring should go here.
 */
final class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Pricing — SCD2 price period recording
        Event::listen(SkuRetailPricingUpdatedEvent::class, RecordPricePeriodListener::class);
    }
}
