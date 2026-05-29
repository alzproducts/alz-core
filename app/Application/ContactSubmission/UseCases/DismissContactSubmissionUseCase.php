<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\ContactSubmission\PotentialConversionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Dismiss a triage-stage potential-conversion row (form submission or call).
 *
 * Triage-only: rejects with {@see InvalidActionStageException} (→ 409) when the row already has
 * any `lead_received` status, regardless of value. The atomic guard inside
 * {@see PotentialConversionAnnotationRepositoryInterface::markDismissed} prevents TOCTOU races
 * against concurrent lead conversion submissions.
 */
final readonly class DismissContactSubmissionUseCase
{
    public function __construct(
        private ContactSubmissionDashboardQueryRepositoryInterface $dashboardQueryRepository,
        private PotentialConversionAnnotationRepositoryInterface $annotationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the row does not exist → HTTP 404
     * @throws InvalidActionStageException When the row is past Triage → HTTP 409
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(Guid $sourceId): void
    {
        $id = $sourceId->value;

        $this->logger->info('Dismissing contact submission', [
            'source_id' => $id,
        ]);

        $this->ensureInTriageStage($id);

        $this->annotationRepository->markDismissed($id);

        $this->logger->info('Dismissed contact submission', [
            'source_id' => $id,
        ]);
    }

    /**
     * @throws RecordNotFoundException
     * @throws InvalidActionStageException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureInTriageStage(string $sourceId): void
    {
        $row = $this->dashboardQueryRepository->findById($sourceId);

        if ($row->leadStatus !== null) {
            throw new InvalidActionStageException(
                submissionId: $sourceId,
                action: ActionType::LeadReceived,
                currentStatus: $row->leadStatus,
            );
        }
    }
}
