<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

/**
 * Asynchronously synchronize product enrichment lookup table to Mixpanel.
 *
 * Syncs product metadata (category, supplier) enabling Mixpanel reports to
 * enrich any event with sku property (e.g., add to cart, purchase).
 *
 * Implements exponential backoff for rate limit and transient error handling.
 */
final class SyncProductLookupTableJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Doubled from original 3 to allow middleware-consumed attempts.
     */
    public int $tries = 6;

    /**
     * Maximum number of unhandled exceptions before failing.
     *
     * Matches original $tries — middleware-handled exceptions (release/fail)
     * don't count, only rethrown exceptions decrement this.
     */
    public int $maxExceptions = 3;

    /**
     * Fail the job if it times out.
     */
    public bool $failOnTimeout = true;

    /**
     * Job timeout in seconds.
     *
     * 5 minutes: generous buffer for ~10k SKU query + CSV generation + upload.
     * Expected actual time: < 30 seconds.
     */
    public int $timeout = 300;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 600;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-product-lookup-table';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Job middleware pipeline.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
                ->by('mixpanel')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
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
     * Execute the job: synchronize product enrichment lookup table.
     */
    public function handle(SyncLookupTableUseCase $useCase): void
    {
        $useCase->execute();
    }
}
