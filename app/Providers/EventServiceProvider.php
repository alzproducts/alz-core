<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Domain\Notifications\Events\AdminAlertEvent;
use App\Infrastructure\Notifications\Listeners\AdminAlertSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormFailedSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormProcessedSlackListener;
use App\Infrastructure\Notifications\Listeners\VariantSkusGeneratedSlackListener;
use App\Infrastructure\Operations\Listeners\RecordPricePeriodListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
        // Pricing — SCD2 price period recording
        Event::listen(SkuRetailPricingUpdatedEvent::class, RecordPricePeriodListener::class);

        // Inventory — Slack notification for variant SKU generation
        Event::listen(VariantSkusGeneratedEvent::class, VariantSkusGeneratedSlackListener::class);

        // Contact submissions — Slack notifications for success/failure
        Event::listen(ContactFormProcessedEvent::class, ContactFormProcessedSlackListener::class);
        Event::listen(ContactFormProcessingFailedEvent::class, ContactFormFailedSlackListener::class);

        // Admin alerts — Slack notification
        Event::listen(AdminAlertEvent::class, AdminAlertSlackListener::class);
    }
}
