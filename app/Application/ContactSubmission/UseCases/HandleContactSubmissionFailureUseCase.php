<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles permanent failure of contact submission processing.
 *
 * Encapsulates the business response when all retries are exhausted:
 * 1. Mark action as failed (prevents stale-action cleanup loop)
 * 2. Validate email (best-effort, for support team)
 * 3. Fire failure event for notifications
 *
 * Called from ProcessContactSubmissionJob::failed() — a framework callback
 * that delegates business logic here.
 */
final readonly class HandleContactSubmissionFailureUseCase
{
    public function __construct(
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private EmailValidationServiceInterface $emailValidator,
        private LoggerInterface $logger,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Handle permanent failure of a contact submission processing attempt.
     *
     * All operations are best-effort: individual failures are logged but
     * do not prevent the failure event from firing.
     */
    public function execute(string $submissionId, string $actionId, string $exceptionMessage, int $attempts): void
    {
        $this->logger->error('Contact submission processing permanently failed', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'exception' => $exceptionMessage,
            'attempts' => $attempts,
        ]);

        $this->markActionFailed($actionId, $exceptionMessage, $attempts);

        $emailValid = $this->validateEmail($submissionId);

        $this->eventDispatcher->dispatch(new ContactFormProcessingFailedEvent(
            submissionId: $submissionId,
            exceptionMessage: $exceptionMessage,
            emailValid: $emailValid,
        ));
    }

    /**
     * Mark the action as permanently failed to prevent the cleanup job
     * from resetting it back to pending indefinitely.
     */
    private function markActionFailed(string $actionId, string $exceptionMessage, int $attempts): void
    {
        try {
            $this->actionRepository->markFailed(
                $actionId,
                "Retries exhausted after {$attempts} attempts: {$exceptionMessage}",
            );
        } catch (Throwable $e) { // @ignoreException - must not throw; cleanup job will handle retries
            $this->logger->critical('Failed to mark action as failed after job exhaustion', [
                'action_id' => $actionId,
                'original_exception' => $exceptionMessage,
                'mark_failed_exception' => $e::class,
                'mark_failed_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate the submission email for the failure notification.
     *
     * Helps the support team know if the email is reachable.
     * Best-effort: returns null if validation fails for any reason.
     */
    private function validateEmail(string $submissionId): ?bool
    {
        $emailValid = null;

        try {
            $submission = $this->submissionRepository->findById($submissionId);
            $emailValid = $this->emailValidator->isValid($submission->form->email);
        } catch (Throwable $e) { // @ignoreException - email validity is non-critical
            $this->logger->warning('Could not validate email for failure notification', [
                'submission_id' => $submissionId,
                'exception' => $e::class,
            ]);
        }

        return $emailValid;
    }
}
