<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Results\SubmitContactFormResult;
use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates contact form submission persistence and async processing.
 *
 * Flow:
 * 1. Save immutable submission snapshot to public_ingest.contact_submissions
 * 2. Create action record in customer_service.contact_submission_actions (status: pending)
 * 3. Dispatch async job for HelpScout processing
 * 4. Return submission ID for HTTP response
 */
final readonly class SubmitContactFormUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private DatabaseGatewayInterface $database,
        private ContactFormDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Persist a contact form submission, create processing action, and dispatch async job.
     *
     * Both persistence operations are atomic - if action creation fails, submission is rolled back.
     * The job is dispatched AFTER the transaction commits to prevent processing non-existent records.
     *
     * @throws DatabaseOperationFailedException On insert failure (permanent)
     * @throws DuplicateRecordException On unique constraint violation (permanent)
     * @throws ExternalServiceUnavailableException On transient database failure (retry)
     */
    public function execute(ContactSubmission $submission): SubmitContactFormResult
    {
        $this->logSubmissionReceived($submission);

        $result = $this->persistAndCreateAction($submission);

        $this->dispatcher->dispatchContactSubmissionProcessing($result->submissionId, $result->actionId);

        $this->logSubmissionDispatched($result);

        return $result;
    }

    private function logSubmissionReceived(ContactSubmission $submission): void
    {
        $this->logger->info('Contact submission received', [
            'reason' => $submission->form->reason->value,
            'email_hash' => \hash('sha256', $submission->form->email),
            'has_phone' => $submission->form->phone !== null,
            'has_order_number' => $submission->form->orderNumber !== null,
            'has_product_context' => $submission->product !== null,
            'has_shopwired_customer_id' => $submission->shopwiredCustomerId !== null,
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException On insert failure (permanent)
     * @throws DuplicateRecordException On unique constraint violation (permanent)
     * @throws ExternalServiceUnavailableException On transient database failure (retry)
     */
    private function persistAndCreateAction(ContactSubmission $submission): SubmitContactFormResult
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

    private function logSubmissionDispatched(SubmitContactFormResult $result): void
    {
        $this->logger->info('Contact submission persisted and dispatched', [
            'submission_id' => $result->submissionId,
            'action_id' => $result->actionId,
        ]);
    }
}
