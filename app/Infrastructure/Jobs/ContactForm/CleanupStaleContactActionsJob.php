<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ContactForm;

use App\Application\ContactSubmission\UseCases\CleanupStaleContactActionsUseCase;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cleans up contact submission actions stuck in 'processing' status.
 *
 * Scheduled to run hourly. Delegates to UseCase which finds stale actions,
 * resets them to 'pending', and re-dispatches the processing job.
 */
final class CleanupStaleContactActionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public int $timeout = 120;

    /**
     * Backoff delays in seconds.
     * 1min, 5min - quick retries for transient DB issues.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300];

    /**
     * Seconds the job can be uniquely locked.
     */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'cleanup-stale-contact-actions';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new HandleApiExceptions()];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    public function handle(CleanupStaleContactActionsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
