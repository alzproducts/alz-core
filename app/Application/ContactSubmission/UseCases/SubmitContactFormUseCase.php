<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Results\SubmitContactFormResult;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Orchestrates contact form submission persistence.
 *
 * Flow:
 * 1. Save immutable submission snapshot to public_ingest.contact_submissions
 * 2. Create action record in customer_service.contact_submission_actions (status: pending)
 * 3. Return IDs for caller to dispatch processing job
 *
 * Note: This UseCase does NOT dispatch the job. The caller (Controller) dispatches
 * after receiving the result, following Clean Architecture layer boundaries.
 */
final readonly class SubmitContactFormUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private DatabaseGatewayInterface $database,
    ) {}

    /**
     * Persist a contact form submission and create processing action.
     *
     * Both operations are atomic - if action creation fails, submission is rolled back.
     * This prevents orphan submissions that never get processed.
     *
     * @throws DatabaseOperationFailedException On insert failure (permanent)
     * @throws DuplicateRecordException On unique constraint violation (permanent)
     * @throws ExternalServiceUnavailableException On transient database failure (retry)
     */
    public function execute(ContactSubmission $submission): SubmitContactFormResult
    {
        return $this->database->transact(function () use ($submission): SubmitContactFormResult {
            $submissionId = $this->submissionRepository->save($submission);

            $actionId = $this->actionRepository->create(
                submissionId: $submissionId,
                actionType: ActionType::HelpScout,
            );

            return new SubmitContactFormResult(
                submissionId: $submissionId,
                actionId: $actionId,
            );
        });
    }
}
