<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fails a claimed contact submission action and notifies support (ADR-0012).
 *
 * The failure event fires only when this claim holder's `failClaim` changed the row,
 * so a lost claim never notifies.
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
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(string $submissionId, string $actionId, string $claimToken, string $errorMessage): bool
    {
        $this->logger->error('Contact submission processing permanently failed', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'exception' => $errorMessage,
        ]);

        if (! $this->actionRepository->failClaim($actionId, $claimToken, $errorMessage)) {
            $this->logger->warning('Contact submission action claim lost', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
            ]);

            return false;
        }

        $this->notifyFailed($submissionId, $errorMessage);

        return true;
    }

    private function notifyFailed(string $submissionId, string $errorMessage): void
    {
        $this->eventDispatcher->dispatch(new ContactFormProcessingFailedEvent(
            submissionId: $submissionId,
            exceptionMessage: $errorMessage,
            emailValid: $this->validateEmail($submissionId),
        ));
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
