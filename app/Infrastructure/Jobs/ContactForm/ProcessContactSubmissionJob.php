<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ContactForm;

use App\Application\ContactSubmission\UseCases\HandleContactSubmissionFailureUseCase;
use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Processes a contact submission by creating a HelpScout conversation.
 *
 * Uses ShouldBeUnique by submission ID to prevent duplicate HelpScout tickets
 * if the job is retried while another instance is still processing.
 *
 * Exception Strategy:
 * - ExternalServiceUnavailableException: Retry with backoff (transient)
 * - AuthenticationExpiredException: Fail immediately (credentials need updating)
 * - InvalidApiRequestException/UnexpectedApiResultException: Fail immediately (code needs fixing)
 * - ResourceNotFoundException/MalformedStoredDataException: Fail immediately (data issue)
 * - InsufficientDataException: Fail immediately (validation should have caught this)
 */
final class ProcessContactSubmissionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum attempts before permanent failure.
     * 5 attempts = initial + 4 retries (using all 4 backoff delays).
     */
    public int $tries = 5;

    /**
     * Maximum exceptions before permanent failure.
     */
    public int $maxExceptions = 5;

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 60;

    /**
     * Backoff delays in seconds.
     * 1min, 5min, 1hr, 12hr - progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600, 43200];

    /**
     * Unique lock duration in seconds.
     * Set to max expected runtime + buffer.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $submissionId,
        public readonly string $actionId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for this job.
     * Prevents duplicate processing of the same submission.
     */
    public function uniqueId(): string
    {
        return $this->submissionId;
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When HelpScout unavailable (triggers retry)
     * @throws Throwable On unexpected errors
     */
    public function handle(
        ProcessContactSubmissionUseCase $useCase,
        ContactSubmissionActionRepositoryInterface $actionRepository,
        LoggerInterface $logger,
    ): void {
        $logger->info('ProcessContactSubmissionJob starting', [
            'submission_id' => $this->submissionId,
            'action_id' => $this->actionId,
            'attempt' => $this->attempts(),
        ]);

        // Track attempt count for monitoring
        $actionRepository->incrementAttempts($this->actionId);

        try {
            $conversationId = $useCase->execute($this->submissionId, $this->actionId);

            $logger->info('ProcessContactSubmissionJob completed', [
                'submission_id' => $this->submissionId,
                'conversation_id' => $conversationId,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('Contact processing service unavailable, will retry', [
                'submission_id' => $this->submissionId,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempt' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need updating

            $actionRepository->markFailed($this->actionId, "Authentication expired: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (InvalidApiRequestException|UnexpectedApiResultException $e) {
            // Permanent failure - code needs fixing

            $actionRepository->markFailed($this->actionId, "API error: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (ResourceNotFoundException|MalformedStoredDataException $e) {
            // Permanent failure - data issue

            $actionRepository->markFailed($this->actionId, "Data error: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (InsufficientDataException $e) {
            // Permanent failure - validation should have caught this

            $actionRepository->markFailed($this->actionId, "Insufficient data: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (Throwable $e) {
            $actionRepository->markFailed($this->actionId, "Unexpected error: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     *
     * This is called when:
     * - ExternalServiceUnavailableException exhausts all retries (transient failures)
     * - Any exception causes Laravel to give up
     *
     * Critical: Must mark action as failed to prevent infinite loop with
     * CleanupStaleContactActionsJob (which resets 'processing' → 'pending').
     */
    public function failed(Throwable $exception): void
    {
        /** @var HandleContactSubmissionFailureUseCase $useCase */
        $useCase = \app(HandleContactSubmissionFailureUseCase::class);
        $useCase->execute(
            submissionId: $this->submissionId,
            actionId: $this->actionId,
            exceptionMessage: $exception->getMessage(),
            attempts: $this->attempts(),
        );
    }
}
