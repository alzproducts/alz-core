<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncProductsUseCase;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asynchronously synchronize ShopWired products to local database.
 *
 * Performs full catalog sync only. ShopWired Products API doesn't support
 * date-based sorting, making incremental sync impractical.
 *
 * Usage:
 * - Full sync: SyncShopwiredProductsJob::dispatch() — daily at 09:00 UK, ~2-5 min
 */
final class SyncShopwiredProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 10;

    /**
     * Maximum exceptions allowed before failing.
     */
    public int $maxExceptions = 5;

    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying (exponential backoff).
     *
     * Shorter delays than customers/orders due to faster runtime (~2-5 min).
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120, 240];

    /**
     * Job timeout in seconds.
     *
     * Set to 15 minutes to accommodate full sync of ~1,500 products.
     */
    public int $timeout = 900;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 1200;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-shopwired-products';
    }

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ServiceRateLimiter::shopwiredApi(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncProductsUseCase $useCase): void
    {
        $useCase->execute();
    }

}
