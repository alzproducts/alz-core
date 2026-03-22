<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncAllStockItemsUseCase;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asynchronously synchronize Linnworks stock items to local database.
 *
 * Full sync strategy: fetches all ~10k stock items with extended properties
 * and upserts them to the database. Designed for daily 5am execution.
 *
 * Usage:
 * - Full sync: SyncLinnworksStockItemsJob::dispatch() — daily at 5am, ~2-5 min
 */
final class SyncLinnworksStockItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every 15 min — next scheduled run is implicit retry.
     */
    public int $tries = 4;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying.
     *
     * Single short retry; fail fast and let next schedule handle it.
     *
     * @var array<int>
     */
    public array $backoff = [60];

    /**
     * Job timeout in seconds.
     *
     * Set to 60 minutes to accommodate full sync of ~10k items.
     * Expected runtime: ~2-5 minutes under normal conditions.
     */
    public int $timeout = 3600;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 4200;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-stock-items';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncAllStockItemsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
