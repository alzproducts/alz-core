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
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\ValueObjects\IntId;
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
        // Idempotency check - skip if already completed to prevent duplicate tickets
        $status = $this->actionRepository->getStatus($actionId);
        if ($status === ActionStatus::Completed) {
            return null;
        }

        // Mark as processing (sets processing_started_at for stale detection)
        $this->actionRepository->markProcessing($actionId);

        // Load submission from database
        $submission = $this->submissionRepository->findById($submissionId);

        // Transform to HelpScout command
        $command = $this->transformer->transform($submission);

        // Create HelpScout conversation
        $conversationId = $this->helpScoutClient->createConversationFromCustomer($command);

        // Validate email and add warning note if invalid (non-blocking)
        $this->addEmailValidationNoteIfInvalid($submission->form->email, $conversationId);

        // Mark completed with external ID for reference
        $this->actionRepository->markCompleted($actionId, (string) $conversationId);

        // Fire success event for notifications (queued listener handles Slack)
        \event(new ContactFormProcessedEvent(
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

        try {
            $noteText = "⚠️ Email validation warning: The email address '{$email}' may be invalid "
                . '(failed RFC/DNS check). Please verify before replying.';

            $this->helpScoutClient->addNoteToConversation(
                IntId::from($conversationId),
                $noteText,
                $this->helpScoutSystemUserId->value,
            );

            $this->logger->info('Added email validation warning note to HelpScout conversation', [
                'conversation_id' => $conversationId,
            ]);
        } catch (Throwable $e) { // @ignoreException - Non-critical: note failure shouldn't fail submission
            $this->logger->error('Failed to add email validation note to HelpScout conversation', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
