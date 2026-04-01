<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Domain\Notifications\Events\AdminAlertEvent;
use App\Domain\Notifications\Events\ManagerAlertEvent;
use App\Infrastructure\Linnworks\Listeners\UpdateLinnworksSellingPriceEpsListener;
use App\Infrastructure\Notifications\Listeners\AdminAlertSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormFailedSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormProcessedSlackListener;
use App\Infrastructure\Notifications\Listeners\HorizonLongWaitSlackListener;
use App\Infrastructure\Notifications\Listeners\ManagerAlertSlackListener;
use App\Infrastructure\Notifications\Listeners\ProductPricingUpdatedSlackListener;
use App\Infrastructure\Notifications\Listeners\VariantSkusGeneratedSlackListener;
use App\Infrastructure\Operations\Listeners\RecordPricePeriodListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * Centralised event → listener wiring.
 *
 * Non-deferred so all listeners register eagerly on boot.
 * Keeps event wiring separate from service bindings, allowing
 * feature providers to remain deferred.
 */
final class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerDomainEventListeners();
        $this->registerAlertListeners();
    }

    private function registerDomainEventListeners(): void
    {
        Event::listen(SkuRetailPricingUpdatedEvent::class, RecordPricePeriodListener::class);
        Event::listen(SkuRetailPricingUpdatedEvent::class, UpdateLinnworksSellingPriceEpsListener::class);
        Event::listen(ProductPricingUpdatedEvent::class, ProductPricingUpdatedSlackListener::class);
        Event::listen(VariantSkusGeneratedEvent::class, VariantSkusGeneratedSlackListener::class);
        Event::listen(ContactFormProcessedEvent::class, ContactFormProcessedSlackListener::class);
        Event::listen(ContactFormProcessingFailedEvent::class, ContactFormFailedSlackListener::class);
    }

    private function registerAlertListeners(): void
    {
        Event::listen(AdminAlertEvent::class, AdminAlertSlackListener::class);
        Event::listen(ManagerAlertEvent::class, ManagerAlertSlackListener::class);
        Event::listen(LongWaitDetected::class, HorizonLongWaitSlackListener::class);
    }
}
