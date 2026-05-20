<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Dismiss a triage-stage contact submission.
 *
 * Triage-only: rejects with {@see InvalidActionStageException} (→ 409) when any `lead_received`
 * action row exists for the submission, regardless of status. The atomic guard inside
 * {@see ContactSubmissionAnnotationRepositoryInterface::markDismissed} prevents TOCTOU races
 * against concurrent lead conversion submissions.
 */
final readonly class DismissContactSubmissionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ContactSubmissionAnnotationRepositoryInterface $annotationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the submission does not exist → HTTP 404
     * @throws InvalidActionStageException When the submission is past Triage → HTTP 409
     * @throws MalformedStoredDataException When stored submission JSONB is corrupted
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(string $submissionId): void
    {
        $this->logger->info('Dismissing contact submission', [
            'submission_id' => $submissionId,
        ]);

        $this->ensureSubmissionInTriageStage($submissionId);

        $this->annotationRepository->markDismissed($submissionId);

        $this->logger->info('Dismissed contact submission', [
            'submission_id' => $submissionId,
        ]);
    }

    /**
     * @throws RecordNotFoundException
     * @throws InvalidActionStageException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureSubmissionInTriageStage(string $submissionId): void
    {
        $this->submissionRepository->findById($submissionId);

        $leadStatus = $this->actionRepository->findActionStatus($submissionId, ActionType::LeadReceived);
        if ($leadStatus !== null) {
            throw new InvalidActionStageException(
                submissionId: $submissionId,
                action: ActionType::LeadReceived,
                currentStatus: $leadStatus,
            );
        }
    }
}
