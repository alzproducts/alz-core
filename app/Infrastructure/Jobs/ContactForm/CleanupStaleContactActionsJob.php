<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ContactForm;

use App\Application\ContactSubmission\UseCases\CleanupStaleContactActionsUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Recovers contact submission actions with an expired lease or overdue in 'pending'.
 *
 * Scheduled every five minutes. Delegates to the UseCase, which re-dispatches each
 * recovered action to its own pipeline.
 */
final class CleanupStaleContactActionsJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 6;

    public int $maxExceptions = 3;

    public int $timeout = 110;

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
        return [...parent::middleware(), new HandleApiExceptions()];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(CleanupStaleContactActionsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
