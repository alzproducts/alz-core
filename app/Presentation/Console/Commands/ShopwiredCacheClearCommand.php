<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Shopwired\Services\CachingShopwiredService;
use Illuminate\Console\Command;

/**
 * Clear ShopWired API cache.
 *
 * Use this command to manually invalidate cached ShopWired data when:
 * - Payment methods are updated in ShopWired admin
 * - Debugging stale data issues
 * - After configuration changes
 *
 * @example php artisan shopwired:cache-clear              # Clear all
 * @example php artisan shopwired:cache-clear payment-methods
 */
final class ShopwiredCacheClearCommand extends Command
{
    protected $signature = 'shopwired:cache-clear
        {resource? : The resource to clear (payment-methods, all). Default: all}';

    protected $description = 'Clear ShopWired API cache';

    public function handle(CachingShopwiredService $service): int
    {
        /** @var string $resource Null coalesced to 'all' */
        $resource = $this->argument('resource') ?? 'all';

        return match ($resource) {
            'payment-methods' => $this->clearPaymentMethods($service),
            'all' => $this->clearAll($service),
            default => $this->invalidResource($resource),
        };
    }

    private function clearPaymentMethods(CachingShopwiredService $service): int
    {
        $service->invalidatePaymentMethods();
        $this->info('ShopWired cache cleared: payment-methods');

        return self::SUCCESS;
    }

    private function clearAll(CachingShopwiredService $service): int
    {
        $service->invalidateAll();
        $this->info('ShopWired cache cleared: all');

        return self::SUCCESS;
    }

    private function invalidResource(string $resource): int
    {
        $this->error("Unknown resource: {$resource}");
        $this->line('Available: payment-methods, all');

        return self::FAILURE;
    }
}
