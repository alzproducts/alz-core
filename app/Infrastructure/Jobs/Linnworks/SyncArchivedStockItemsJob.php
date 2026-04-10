<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncArchivedStockItemsUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
 * Asynchronously sync full archived stock items from Linnworks.
 *
 * Pulls archived rows via the SQL Dashboards endpoint (the Inventory REST
 * API silently filters them out) and upserts them into `linnworks.stock_items`.
 * Designed for weekly execution — a complementary path to the hourly
 * {@see SyncArchivedStockItemFlagsJob} which only flips flags on rows
 * already present locally.
 */
final class SyncArchivedStockItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Weekly full sync — fewer retries than the hourly flag sync.
     */
    public int $tries = 3;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    public bool $failOnTimeout = true;

    /**
     * Backoff schedule in seconds: 5 minutes, then 15 minutes.
     *
     * @var array<int>
     */
    public array $backoff = [300, 900];

    /**
     * Job timeout in seconds (15 minutes).
     *
     * Generous headroom for fetching + upserting ~3.6k archived rows.
     */
    public int $timeout = 900;

    /**
     * Fallback ceiling for the unique lock if the job is orphaned.
     *
     * Laravel releases the lock automatically when `handle()` returns or
     * all retries are exhausted; this value only matters if the worker is
     * SIGKILLed mid-run. Aligned with {@see retryUntil()} (6 hours) — the
     * longest window in which a retry could still be in flight.
     */
    public int $uniqueFor = 21600;

    public function uniqueId(): string
    {
        return 'sync-archived-stock-items';
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
        return \now()->addHours(6)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(SyncArchivedStockItemsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
