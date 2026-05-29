<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Enums\PotentialConversionSource;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\ContactSubmission\PotentialConversionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\ContactSubmission\Exceptions\OperationNotSupportedForSourceException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Mark an awaiting-quote submission as "no quote expected" — clears `is_potential_quote`.
 *
 * Form-only: call rows are rejected with {@see OperationNotSupportedForSourceException} (→ 409)
 * because calls have no quote tracking yet. Two further stage guards (both → 409):
 *  - Lead must be completed (else there's nothing to demote from awaiting-quote yet).
 *  - No quote action of any status (else a quote is already in flight or sent).
 *
 * The atomic guard inside {@see PotentialConversionAnnotationRepositoryInterface::markNoQuoteExpected}
 * additionally closes the race against a concurrent `POST /conversions/quote` that may slip in
 * between the pre-check and the write.
 */
final readonly class MarkNoQuoteExpectedUseCase
{
    public function __construct(
        private ContactSubmissionDashboardQueryRepositoryInterface $dashboardQueryRepository,
        private PotentialConversionAnnotationRepositoryInterface $annotationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the row does not exist → HTTP 404
     * @throws OperationNotSupportedForSourceException When the row is a call (form-only endpoint) → HTTP 409
     * @throws InvalidActionStageException When the row is not in Awaiting Quote stage → HTTP 409
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(Guid $submissionId): void
    {
        $id = $submissionId->value;

        $this->logger->info('Marking contact submission no-quote-expected', [
            'source_id' => $id,
        ]);

        $this->ensureFormRowAwaitingQuote($id);

        $this->writeAnnotationAndLog($id);
    }

    /**
     * @throws RecordNotFoundException
     * @throws OperationNotSupportedForSourceException
     * @throws InvalidActionStageException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureFormRowAwaitingQuote(string $sourceId): void
    {
        $row = $this->dashboardQueryRepository->findById($sourceId);

        if ($row->source !== PotentialConversionSource::Form) {
            throw new OperationNotSupportedForSourceException(
                sourceId: $sourceId,
                source: $row->source->value,
                operation: 'markNoQuoteExpected',
            );
        }

        $this->ensureAwaitingQuoteStage($sourceId, $row->leadStatus, $row->quoteStatus);
    }

    /**
     * @throws InvalidActionStageException
     */
    private function ensureAwaitingQuoteStage(string $sourceId, ?ActionStatus $leadStatus, ?ActionStatus $quoteStatus): void
    {
        if ($leadStatus !== ActionStatus::Completed) {
            throw new InvalidActionStageException(
                submissionId: $sourceId,
                action: ActionType::LeadReceived,
                currentStatus: $leadStatus,
            );
        }

        if ($quoteStatus !== null) {
            throw new InvalidActionStageException(
                submissionId: $sourceId,
                action: ActionType::QuoteIssued,
                currentStatus: $quoteStatus,
            );
        }
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function writeAnnotationAndLog(string $sourceId): void
    {
        $updated = $this->annotationRepository->markNoQuoteExpected($sourceId);

        if (!$updated) {
            $this->logger->warning('markNoQuoteExpected atomic guard fired — concurrent quote action blocked the write', [
                'source_id' => $sourceId,
            ]);

            return;
        }

        $this->logger->info('Marked contact submission no-quote-expected', [
            'source_id' => $sourceId,
        ]);
    }
}
