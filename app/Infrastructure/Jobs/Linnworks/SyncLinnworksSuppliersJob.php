<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncSuppliersUseCase;
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
 * Asynchronously synchronize Linnworks supplier directory to local database.
 *
 * Full-replace strategy: fetches all suppliers, upserts, and reconciles deletions.
 * Designed for hourly execution (small dataset, ~seconds runtime).
 */
final class SyncLinnworksSuppliersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
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
    public array $backoff = [60];

    /**
     * Job timeout in seconds.
     *
     * Set to 2 minutes — supplier directory is a small dataset.
     */
    public int $timeout = 120;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 300;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-suppliers';
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
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncSuppliersUseCase $useCase): void
    {
        $useCase->execute();
    }
}
