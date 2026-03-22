<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncLinnworksCursorUseCase;
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
 * Cursor-based incremental order sync from Linnworks.
 *
 * Runs every minute. Fetches orders modified since the last cursor position.
 * Lightweight — typically processes only a few orders per run.
 */
final class SyncLinnworksOrdersByCursorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every minute — next scheduled run is implicit retry.
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
     * @var array<int>
     */
    public array $backoff = [30];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 90;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-orders-cursor';
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
                ->by('linnworks')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncLinnworksCursorUseCase $useCase): void
    {
        $useCase->execute();
    }
}
