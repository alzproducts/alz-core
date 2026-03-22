<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
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
 * Asynchronously synchronize campaign lookup table from Google Ads to Mixpanel.
 *
 * Queues the campaign lookup table sync to avoid blocking HTTP requests.
 * Implements exponential backoff for rate limit handling.
 */
final class SyncCampaignLookupTableJob implements ShouldBeUnique, ShouldQueue
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
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 300;

    /**
     * Seconds the job can be uniquely locked.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'sync-campaign-lookup-table';
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
            ServiceCircuitBreaker::mixpanel(),
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
     * Execute the job: synchronize campaign lookup table.
     */
    public function handle(SyncLookupTableUseCase $useCase): void
    {
        $useCase->execute();
    }
}
