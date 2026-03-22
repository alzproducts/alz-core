<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncOrdersUseCase;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

/**
 * Asynchronously synchronize ShopWired orders to local database.
 *
 * Supports full sync and quick sync modes with page limits.
 *
 * Usage:
 * - Full sync: SyncShopwiredOrdersJob::dispatch() — monthly, all orders
 * - Quick sync: SyncShopwiredOrdersJob::dispatch(5) — every 6 hours, ~500 orders
 *
 * @see SyncShopwiredOrdersRangeJob For date-range based sync
 */
final class SyncShopwiredOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * 3 attempts: quick retries for transient issues + 1hr fallback for longer outages.
     */
    public int $tries = 6;

    /**
     * Maximum exceptions allowed before failing.
     */
    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    /**
     * Unique lock duration in seconds.
     *
     * Set to max expected runtime + buffer. If job completes sooner,
     * lock releases immediately. If job times out, lock auto-releases.
     */
    public int $uniqueFor = 10000;

    /**
     * Get the unique ID for this job.
     *
     * Returns a fixed ID (ignores constructor params) so ALL sync modes
     * (full/quick) share one lock. This prevents quick syncs from
     * running while a full sync is in progress.
     */
    public function uniqueId(): string
    {
        return 'sync-shopwired-orders';
    }

    /**
     * Create a new job instance.
     *
     * @param int|null $maxPages Max pages to fetch (null = all, 1 page ≈ 100 orders)
     */
    public function __construct(
        private readonly ?int $maxPages = null,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new RateLimited('shopwired-api'),
            (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
                ->by('shopwired')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     * For micro syncs (every 5 min), the 1hr retry never fires — next schedule comes first.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds.
     *
     * Set to 2.5 hours to accommodate full sync of all orders with buffer.
     */
    public int $timeout = 9000;

    public function handle(SyncOrdersUseCase $useCase): void
    {
        $useCase->execute($this->maxPages);
    }

}
