<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Synchronous entry point for marking a contact submission as a qualified lead.
 *
 * Atomic dual-write: the action row (workflow state) and annotation row (`is_potential_quote`
 * captured at conversion time) are inserted in one DB transaction so the dashboard never
 * sees a lead-completed row without its potential-quote classification. The async upload
 * dispatcher fires post-commit, matching the project-wide dispatch-after-commit pattern.
 */
final readonly class SubmitLeadConversionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ContactSubmissionAnnotationRepositoryInterface $annotationRepository,
        private DatabaseGatewayInterface $database,
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
    public function execute(string $submissionId, bool $isPotentialQuote): void
    {
        $this->logger->info('Submitting lead conversion', [
            'submission_id' => $submissionId,
            'is_potential_quote' => $isPotentialQuote,
        ]);

        $submission = $this->submissionRepository->findById($submissionId);
        $this->ensureGclidPresent($submission->attribution->gclid);

        $actionId = $this->writeActionAndAnnotation($submissionId, $isPotentialQuote);

        $this->dispatcher->dispatchLeadConversion(
            new LeadConversionCommand(Guid::fromTrusted($submissionId), Guid::fromTrusted($actionId)),
        );

        $this->logger->info('Lead conversion dispatched', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * Insert action + annotation in a single transaction; returns the new action id.
     *
     * @throws DuplicateRecordException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    private function writeActionAndAnnotation(string $submissionId, bool $isPotentialQuote): string
    {
        return $this->database->transact(function () use ($submissionId, $isPotentialQuote): string {
            $actionId = $this->actionRepository->create($submissionId, ActionType::LeadReceived);

            $this->annotationRepository->upsert(new UpsertAnnotationCommand(
                contactSubmissionId: $submissionId,
                valuesToSet: ['is_potential_quote' => $isPotentialQuote],
                columnsToClear: [],
            ));

            return $actionId;
        });
    }

    /**
     * Treat empty-string gclid the same as null — defensive against form data quirks.
     *
     * @throws InsufficientDataException When gclid is absent or empty
     */
    private function ensureGclidPresent(?string $gclid): void
    {
        if ($gclid === null || $gclid === '') {
            throw new InsufficientDataException('ContactSubmission', 'a gclid for conversion tracking');
        }
    }
}
