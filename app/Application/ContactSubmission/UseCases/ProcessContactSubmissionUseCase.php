<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;

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
    ) {}

    /**
     * Process a contact submission via HelpScout.
     *
     * @param string $submissionId UUID of the contact submission to process
     * @param string $actionId UUID of the action record to update
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
    public function execute(string $submissionId, string $actionId): void
    {
        // Idempotency check - skip if already completed to prevent duplicate tickets
        $status = $this->actionRepository->getStatus($actionId);
        if ($status === ActionStatus::Completed) {
            return;
        }

        // Mark as processing (sets processing_started_at for stale detection)
        $this->actionRepository->markProcessing($actionId);

        // Load submission from database
        $submission = $this->submissionRepository->findById($submissionId);

        // Transform to HelpScout command
        $command = $this->transformer->transform($submission);

        // Create HelpScout conversation
        $conversationId = $this->helpScoutClient->createConversationFromCustomer($command);

        // Mark completed with external ID for reference
        $this->actionRepository->markCompleted($actionId, (string) $conversationId);
    }
}
