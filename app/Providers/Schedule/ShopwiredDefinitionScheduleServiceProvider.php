<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Shopwired\SyncShopwiredCustomFieldsJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredFilterGroupsJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * ShopWired Definition Metadata Schedules.
 *
 * Hourly polling of the small, stable schema/registry datasets that catalog reads
 * depend on. Kept separate from the entity-sync schedules ({@see ShopwiredScheduleServiceProvider})
 * because both are upstream "definition" syncs that must be fresh before data syncs run.
 */
final class ShopwiredDefinitionScheduleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerCustomFieldSchedule();
        $this->registerFilterGroupSchedule();
    }

    /**
     * Custom field definitions describe what custom fields exist for products, categories,
     * customers, etc. Small, stable dataset that changes infrequently but is upstream of
     * category/product syncs — hourly ensures definitions are fresh before daily data syncs.
     */
    private function registerCustomFieldSchedule(): void
    {
        Schedule::job(new SyncShopwiredCustomFieldsJob())
            ->name('sync-shopwired-custom-fields')
            ->hourly()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(5);
    }

    /**
     * Filter groups map option numbers to faceted-navigation groups. Unknown groups
     * fail silently (FilterGroupRegistry::findByOptionNo returns null), producing stale
     * facets with no error signal — hourly polling keeps the registry current.
     */
    private function registerFilterGroupSchedule(): void
    {
        Schedule::job(new SyncShopwiredFilterGroupsJob())
            ->name('sync-shopwired-filter-groups')
            ->hourly()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(5);
    }
}
