<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\ContactForm;

use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\ValueObjects\IntId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    use SerializesModels;

    /**
     * Maximum attempts before permanent failure.
     * 5 attempts = initial + 4 retries (using all 4 backoff delays).
     */
    public int $tries = 5;

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
        $this->onQueue('default');
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
     * @throws ExternalServiceUnavailableException When HelpScout unavailable (triggers retry)
     * @throws Throwable On unexpected errors
     */
    public function handle(
        ProcessContactSubmissionUseCase $useCase,
        ContactSubmissionActionRepositoryInterface $actionRepository,
        ContactSubmissionRepositoryInterface $submissionRepository,
    ): void {
        Log::info('ProcessContactSubmissionJob starting', [
            'submission_id' => $this->submissionId,
            'action_id' => $this->actionId,
            'attempt' => $this->attempts(),
        ]);

        // Track attempt count for monitoring
        $actionRepository->incrementAttempts($this->actionId);

        try {
            $conversationId = $useCase->execute($this->submissionId, $this->actionId);

            Log::info('ProcessContactSubmissionJob completed', [
                'submission_id' => $this->submissionId,
                'conversation_id' => $conversationId,
            ]);

            // Fire success event for notifications (queued listener handles Slack)
            if ($conversationId !== null) {
                $submission = $submissionRepository->findById($this->submissionId);
                \event(new ContactFormProcessedEvent(
                    submissionId: $this->submissionId,
                    conversationId: IntId::from($conversationId),
                    customerName: $submission->form->name,
                    customerEmail: $submission->form->email,
                ));
            }
        } catch (ExternalServiceUnavailableException $e) {
            // Transient failure - retry with API's delay or use backoff
            Log::warning('HelpScout unavailable during contact processing', [
                'submission_id' => $this->submissionId,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempt' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e; // Let Laravel handle with backoff
            }
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need updating
            Log::critical('HelpScout authentication expired', [
                'submission_id' => $this->submissionId,
                'service' => $e->serviceName,
            ]);

            $actionRepository->markFailed($this->actionId, "Authentication expired: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (InvalidApiRequestException|UnexpectedApiResultException $e) {
            // Permanent failure - code needs fixing
            Log::critical('HelpScout API error - code update required', [
                'submission_id' => $this->submissionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $actionRepository->markFailed($this->actionId, "API error: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (ResourceNotFoundException|MalformedStoredDataException $e) {
            // Permanent failure - data issue
            Log::error('Contact submission data error', [
                'submission_id' => $this->submissionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $actionRepository->markFailed($this->actionId, "Data error: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (InsufficientDataException $e) {
            // Permanent failure - validation should have caught this
            Log::error('Contact submission has insufficient data', [
                'submission_id' => $this->submissionId,
                'context' => $e->context,
                'requirement' => $e->requirement,
            ]);

            $actionRepository->markFailed($this->actionId, "Insufficient data: {$e->getMessage()}");
            $this->fail($e);

            throw $e;
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in ProcessContactSubmissionJob - code update required', [
                'submission_id' => $this->submissionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

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
        Log::error('ProcessContactSubmissionJob exhausted all retries', [
            'submission_id' => $this->submissionId,
            'action_id' => $this->actionId,
            'exception' => $exception::class,
            'attempts' => $this->attempts(),
        ]);

        // Mark action as failed to prevent CleanupStaleContactActionsJob from
        // resetting and re-dispatching indefinitely
        try {
            $actionRepository = \app(ContactSubmissionActionRepositoryInterface::class);
            $actionRepository->markFailed(
                $this->actionId,
                "Retries exhausted after {$this->attempts()} attempts: {$exception->getMessage()}",
            );
        } catch (Throwable $e) { // @ignoreException - failed() must not throw; cleanup job will handle retries
            // If we can't mark as failed (DB down), log and let cleanup job handle it
            // This is better than silently leaving it in processing state
            Log::critical('Failed to mark action as failed after job exhaustion', [
                'action_id' => $this->actionId,
                'original_exception' => $exception::class,
                'mark_failed_exception' => $e::class,
                'mark_failed_message' => $e->getMessage(),
            ]);
        }

        // Validate email for the failure notification (helps support team know if email is reachable)
        $emailValid = null;
        try {
            $submissionRepository = \app(ContactSubmissionRepositoryInterface::class);
            $emailValidator = \app(EmailValidationServiceInterface::class);
            $submission = $submissionRepository->findById($this->submissionId);
            $emailValid = $emailValidator->isValid($submission->form->email);
        } catch (Throwable $e) { // @ignoreException - failed() must not throw; email validity is non-critical
            Log::warning('Could not validate email for failure notification', [
                'submission_id' => $this->submissionId,
                'exception' => $e::class,
            ]);
        }

        // Fire failure event for notifications (queued listener handles Slack)
        \event(new ContactFormProcessingFailedEvent(
            submissionId: $this->submissionId,
            exceptionMessage: $exception->getMessage(),
            emailValid: $emailValid,
        ));
    }
}
