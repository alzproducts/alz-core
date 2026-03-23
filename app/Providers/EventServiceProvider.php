<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Product\Events\ProductAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\ProductRemovedFromSaleEvent;
use App\Domain\Catalog\Product\Events\SkuAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\SkuRemovedFromSaleEvent;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Domain\Notifications\Events\AdminAlertEvent;
use App\Domain\Notifications\Events\ManagerAlertEvent;
use App\Infrastructure\Linnworks\Listeners\UpdateLinnworksSaleStateListener;
use App\Infrastructure\Linnworks\Listeners\UpdateLinnworksSellingPriceEpsListener;
use App\Infrastructure\Notifications\Listeners\AdminAlertSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormFailedSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormProcessedSlackListener;
use App\Infrastructure\Notifications\Listeners\HorizonLongWaitSlackListener;
use App\Infrastructure\Notifications\Listeners\ManagerAlertSlackListener;
use App\Infrastructure\Notifications\Listeners\ProductPricingUpdatedSlackListener;
use App\Infrastructure\Notifications\Listeners\VariantSkusGeneratedSlackListener;
use App\Infrastructure\Operations\Listeners\RecordPricePeriodListener;
use App\Infrastructure\Shopwired\Listeners\AddProductToSaleListener;
use App\Infrastructure\Shopwired\Listeners\DetectSaleStateChangeListener;
use App\Infrastructure\Shopwired\Listeners\RemoveProductFromSaleListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;
use Override;

/**
 * Centralised event → listener wiring.
 *
 * Non-deferred so all listeners register eagerly on boot.
 * Keeps event wiring separate from service bindings, allowing
 * feature providers to remain deferred.
 */
final class EventServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->when([
            AddProductToSaleListener::class,
            RemoveProductFromSaleListener::class,
        ])->needs('$saleCategoryId')
            ->give(static function (): int {
                $value = \config('shopwired.sale_category_id');

                if (! \is_numeric($value)) {
                    throw new InvalidConfigurationException(
                        'shopwired.sale_category_id',
                        'shopwired.sale_category_id must be a numeric value',
                    );
                }

                return (int) $value;
            });
    }

    public function boot(): void
    {
        // Pricing — SCD2 price period recording + Slack notification
        Event::listen(SkuRetailPricingUpdatedEvent::class, RecordPricePeriodListener::class);
        Event::listen(SkuRetailPricingUpdatedEvent::class, UpdateLinnworksSellingPriceEpsListener::class);
        Event::listen(ProductPricingUpdatedEvent::class, ProductPricingUpdatedSlackListener::class);
        Event::listen(ProductPricingUpdatedEvent::class, DetectSaleStateChangeListener::class);

        // Sale state — ShopWired side-effects (product-level: one event per product)
        Event::listen(ProductAddedToSaleEvent::class, AddProductToSaleListener::class);
        Event::listen(ProductRemovedFromSaleEvent::class, RemoveProductFromSaleListener::class);

        // Sale state — Linnworks EP updates (SKU-level: one event per variation)
        Event::listen(SkuAddedToSaleEvent::class, UpdateLinnworksSaleStateListener::class);
        Event::listen(SkuRemovedFromSaleEvent::class, UpdateLinnworksSaleStateListener::class);

        // Inventory — Slack notification for variant SKU generation
        Event::listen(VariantSkusGeneratedEvent::class, VariantSkusGeneratedSlackListener::class);

        // Contact submissions — Slack notifications for success/failure
        Event::listen(ContactFormProcessedEvent::class, ContactFormProcessedSlackListener::class);
        Event::listen(ContactFormProcessingFailedEvent::class, ContactFormFailedSlackListener::class);

        // Admin alerts — infrastructure/system-level (queue issues, deployment)
        Event::listen(AdminAlertEvent::class, AdminAlertSlackListener::class);

        // Manager alerts — business-notable events (order deletions, webhook health)
        Event::listen(ManagerAlertEvent::class, ManagerAlertSlackListener::class);

        // Horizon — route long wait alerts to Slack (not queued — queue may be backed up)
        Event::listen(LongWaitDetected::class, HorizonLongWaitSlackListener::class);
    }
}
