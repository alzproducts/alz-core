<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Processes a contact submission by creating a HelpScout conversation.
 *
 * Called by ProcessContactSubmissionJob after submission is persisted.
 * Manages the full processing workflow including status updates.
 *
 * Idempotent: Safe to call multiple times - skips if already completed.
 * This prevents duplicate HelpScout tickets on job retries.
 *
 * Note: markFailed() is NOT called here - the Job handles that based on
 * retry semantics (retryable vs permanent failure, max attempts reached).
 */
final readonly class ProcessContactSubmissionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ConversationWriteClientInterface $helpScoutClient,
        private ContactSubmissionToConversationCommandTransformer $transformer,
        private EmailValidationServiceInterface $emailValidator,
        private LoggerInterface $logger,
        private HelpScoutSystemUserId $helpScoutSystemUserId,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Process a contact submission via HelpScout.
     *
     * @param string $submissionId UUID of the contact submission to process
     * @param string $actionId UUID of the action record to update
     *
     * @return int|null HelpScout conversation ID (null if already completed - idempotent)
     *
     * @throws ResourceNotFoundException When submission not found
     * @throws MalformedStoredDataException When stored data is corrupted
     * @throws DatabaseOperationFailedException When DB operation fails (permanent)
     * @throws ExternalServiceUnavailableException When HelpScout/DB unavailable (transient - retry)
     * @throws AuthenticationExpiredException When HelpScout credentials invalid (permanent)
     * @throws InvalidApiRequestException When HelpScout rejects request (permanent)
     * @throws UnexpectedApiResultException When HelpScout returns unexpected response (permanent)
     * @throws InsufficientDataException When submission lacks required customer data
     */
    public function execute(string $submissionId, string $actionId): ?int
    {
        if ($this->isAlreadyCompleted($actionId)) {
            return null;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $submission = $this->submissionRepository->findById($submissionId);

        return $this->createConversationAndNotify($submission, $submissionId, $actionId);
    }

    /**
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function isAlreadyCompleted(string $actionId): bool
    {
        return $this->actionRepository->getStatus($actionId) === ActionStatus::Completed;
    }

    /**
     * Create HelpScout conversation, validate email, mark complete, and fire event.
     *
     * @throws ExternalServiceUnavailableException When HelpScout/DB unavailable
     * @throws AuthenticationExpiredException When HelpScout credentials invalid
     * @throws InvalidApiRequestException When HelpScout rejects request
     * @throws UnexpectedApiResultException When HelpScout returns unexpected response
     * @throws DatabaseOperationFailedException When DB operation fails
     * @throws InsufficientDataException When submission lacks required customer data
     */
    private function createConversationAndNotify(
        ContactSubmission $submission,
        string $submissionId,
        string $actionId,
    ): int {
        $command = $this->transformer->transform($submission);
        $conversationId = $this->helpScoutClient->createConversationFromCustomer($command);

        $this->addEmailValidationNoteIfInvalid($submission->form->email, $conversationId);
        $this->actionRepository->markCompleted($actionId, (string) $conversationId);

        $this->eventDispatcher->dispatch(new ContactFormProcessedEvent(
            submissionId: $submissionId,
            conversationId: IntId::from($conversationId),
            customerName: $submission->form->name,
            customerEmail: $submission->form->email,
        ));

        return $conversationId;
    }

    /**
     * Add an internal note to HelpScout if the email fails validation.
     *
     * Non-blocking: logs errors but continues processing. The note is
     * informational to help support staff - not critical to the workflow.
     */
    private function addEmailValidationNoteIfInvalid(string $email, int $conversationId): void
    {
        if ($this->emailValidator->isValid($email)) {
            return;
        }

        $noteText = "⚠️ Email validation warning: The email address '{$email}' may be invalid "
            . '(failed RFC/DNS check). Please verify before replying.';

        $this->addNonBlockingNote($conversationId, $noteText);
    }

    /**
     * Fire-and-forget note delivery — logs success/failure but never throws.
     */
    private function addNonBlockingNote(int $conversationId, string $noteText): void
    {
        try {
            $this->helpScoutClient->addNoteToConversation(
                IntId::from($conversationId),
                $noteText,
                $this->helpScoutSystemUserId->value,
            );

            $this->logger->info('Added note to HelpScout conversation', [
                'conversation_id' => $conversationId,
            ]);
        } catch (Throwable $e) { // @ignoreException - Non-critical: note failure shouldn't fail submission
            $this->logger->error('Failed to add note to HelpScout conversation', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
