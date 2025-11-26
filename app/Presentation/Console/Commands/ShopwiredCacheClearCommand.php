<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Shopwired\Services\CachingShopwiredService;
use Illuminate\Console\Command;

/**
 * Clear ShopWired API cache.
 *
 * Use this command to manually invalidate cached ShopWired data when:
 * - Data is updated in ShopWired admin
 * - Debugging stale data issues
 * - After configuration changes
 *
 * @example php artisan shopwired:cache-clear
 */
final class ShopwiredCacheClearCommand extends Command
{
    protected $signature = 'shopwired:cache-clear';

    protected $description = 'Clear ShopWired API cache';

    public function handle(CachingShopwiredService $service): int
    {
        $service->invalidateAll();
        $this->info('ShopWired cache cleared');

        return self::SUCCESS;
    }
}
