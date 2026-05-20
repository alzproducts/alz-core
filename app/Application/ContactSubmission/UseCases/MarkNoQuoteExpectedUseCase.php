<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Mark an awaiting-quote submission as "no quote expected" — clears `is_potential_quote`.
 *
 * Two stage guards in the pre-check (both → 409):
 *  - Lead must be completed (else there's nothing to demote from awaiting-quote yet).
 *  - No quote action of any status (else a quote is already in flight or sent).
 *
 * The atomic guard inside {@see ContactSubmissionAnnotationRepositoryInterface::markNoQuoteExpected}
 * additionally closes the race against a concurrent `POST /conversions/quote` that may slip in
 * between the pre-check and the write.
 */
final readonly class MarkNoQuoteExpectedUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ContactSubmissionAnnotationRepositoryInterface $annotationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the submission does not exist → HTTP 404
     * @throws InvalidActionStageException When the submission is not in Awaiting Quote stage → HTTP 409
     * @throws MalformedStoredDataException When stored submission JSONB is corrupted
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(string $submissionId): void
    {
        $this->logger->info('Marking contact submission no-quote-expected', [
            'submission_id' => $submissionId,
        ]);

        $this->submissionRepository->findById($submissionId);
        $this->ensureLeadCompleted($submissionId);
        $this->ensureNoQuoteAction($submissionId);

        $this->annotationRepository->markNoQuoteExpected($submissionId);

        $this->logger->info('Marked contact submission no-quote-expected', [
            'submission_id' => $submissionId,
        ]);
    }

    /**
     * @throws InvalidActionStageException When the lead action is missing or not yet completed
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureLeadCompleted(string $submissionId): void
    {
        $status = $this->actionRepository->findActionStatus($submissionId, ActionType::LeadReceived);
        if ($status === ActionStatus::Completed) {
            return;
        }

        throw new InvalidActionStageException(
            submissionId: $submissionId,
            action: ActionType::LeadReceived,
            currentStatus: $status,
        );
    }

    /**
     * @throws InvalidActionStageException When a quote action of any status already exists
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureNoQuoteAction(string $submissionId): void
    {
        $quoteStatus = $this->actionRepository->findActionStatus($submissionId, ActionType::QuoteIssued);
        if ($quoteStatus === null) {
            return;
        }

        throw new InvalidActionStageException(
            submissionId: $submissionId,
            action: ActionType::QuoteIssued,
            currentStatus: $quoteStatus,
        );
    }
}
