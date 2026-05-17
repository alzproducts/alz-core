<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Synchronous entry point for marking a contact submission as a qualified lead.
 *
 * Validates the submission, ensures a gclid is present (required for Google Ads
 * offline conversion attribution), creates the action row in pending state, then
 * dispatches the async upload job.
 *
 * The DB insert + dispatch run without a transaction — only one row is written
 * (the action) and `DuplicateRecordException` handles the idempotency case at
 * the row level.
 */
final readonly class SubmitLeadConversionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ConversionDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the submission is missing → HTTP 404
     * @throws InsufficientDataException When the submission lacks a gclid → HTTP 400
     * @throws DuplicateRecordException When a lead action already exists → HTTP 409
     * @throws MalformedStoredDataException When stored submission JSONB is corrupted
     * @throws DatabaseOperationFailedException When the action insert fails (permanent)
     * @throws ExternalServiceUnavailableException When the database is transiently unavailable
     */
    public function execute(string $submissionId): void
    {
        $submission = $this->submissionRepository->findById($submissionId);

        // Treat empty-string gclid the same as null — defensive against form data quirks.
        $gclid = $submission->attribution->gclid;
        if ($gclid === null || $gclid === '') {
            throw new InsufficientDataException('ContactSubmission', 'a gclid for conversion tracking');
        }

        $actionId = $this->actionRepository->create($submissionId, ActionType::LeadReceived);

        $this->dispatcher->dispatchLeadConversion(
            new LeadConversionCommand($submissionId, $actionId),
        );

        $this->logger->info('Lead conversion dispatched', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }
}
