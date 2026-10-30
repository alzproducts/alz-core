<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ContactForm;

use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\Exceptions\Data\AbstractDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Processes a contact submission by creating a HelpScout conversation.
 *
 * Uses ShouldBeUnique by submission ID to prevent duplicate HelpScout tickets
 * if the job is retried while another instance is still processing.
 *
 * The use case owns the action row (ADR-0012); the job never writes it, so it defines no failed().
 *
 * Exception Strategy:
 * - TransientApiFailure: Handled by {@see HandleApiExceptions} middleware (release/rethrow)
 * - PermanentApiFailure: Handled by {@see HandleApiExceptions} middleware (fail immediately)
 * - Data exceptions and ActionTypeMismatchException: Caught in handle() → fail immediately
 * - Unexpected Throwable: Retried by Laravel; the action stays `processing` until the lease sweep
 */
final class ProcessContactSubmissionJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Maximum attempts before permanent failure.
     * 5 attempts = initial + 4 retries (using all 4 backoff delays).
     */
    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 75;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr, 12hr: progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600, 43200];

    /** Unique lock duration in seconds. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $submissionId,
        public readonly string $actionId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->submissionId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            // intentional: outside the breaker, so the breaker still counts transients carrying retryAfter (COR-235)
            new HandleApiExceptions(),
            ServiceCircuitBreaker::helpscout(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(14)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(ProcessContactSubmissionUseCase $useCase): void
    {
        try {
            $useCase->execute($this->submissionId, $this->actionId);
        } catch (ActionTypeMismatchException|AbstractDataException $e) {
            $this->fail($e);
        }
    }
}
