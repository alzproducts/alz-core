<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\ContactSubmission\Resolvers\ExistingConversationResolver;
use App\Application\ContactSubmission\Services\EmailValidationNoteService;
use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\ContactSubmission\ValueObjects\ActionAttempts;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\AbstractDataException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Creates the HelpScout conversation for a contact submission under a claim on its action (ADR-0012).
 *
 * The claim holder owns every terminal write: a transient failure returns the action to
 * `pending`, a permanent one fails it through {@see HandleContactSubmissionFailureUseCase},
 * and any other throwable leaves it `processing` for the lease sweep.
 */
final readonly class ProcessContactSubmissionUseCase
{
    private const string ATTEMPT_LIMIT_MESSAGE = 'Attempt limit reached';

    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ConversationWriteClientInterface $helpScoutClient,
        private ContactSubmissionToConversationCommandTransformer $transformer,
        private EmailValidationNoteService $emailValidationNote,
        private LoggerInterface $logger,
        private Dispatcher $eventDispatcher,
        private HandleContactSubmissionFailureUseCase $failureHandler,
        private ExistingConversationResolver $existingConversation,
    ) {}

    /**
     * @throws ActionTypeMismatchException When the action belongs to another pipeline
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When HelpScout or the database is unavailable; carries the retry delay
     * @throws PermanentApiFailure After failing the action
     * @throws AbstractDataException After failing the action
     */
    public function execute(string $submissionId, string $actionId): void
    {
        $this->logger->info('Processing contact submission', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);

        $claim = $this->claimHelpScoutAction($actionId);

        if ($claim === null) {
            return;
        }

        if (new ActionAttempts($claim->attempts)->isPastLimit()) {
            $this->failureHandler->execute($submissionId, $actionId, $claim->token, self::ATTEMPT_LIMIT_MESSAGE);

            return;
        }

        $this->processClaim($submissionId, $actionId, $claim);
    }

    /**
     * @throws ActionTypeMismatchException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function claimHelpScoutAction(string $actionId): ?ActionClaimDTO
    {
        if (! $this->isHelpScoutAction($actionId)) {
            return null;
        }

        $claim = $this->actionRepository->claim($actionId);

        if ($claim === null) {
            $this->logger->info('Contact submission action not claimable', ['action_id' => $actionId]);
        }

        return $claim;
    }

    /**
     * @throws ActionTypeMismatchException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function isHelpScoutAction(string $actionId): bool
    {
        $actionType = $this->actionRepository->findActionType($actionId);

        if ($actionType === null) {
            $this->logger->info('Contact submission action not found', ['action_id' => $actionId]);

            return false;
        }

        if ($actionType !== ActionType::HelpScout) {
            throw new ActionTypeMismatchException($actionId, ActionType::HelpScout, $actionType);
        }

        return true;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws PermanentApiFailure
     * @throws AbstractDataException
     */
    private function processClaim(string $submissionId, string $actionId, ActionClaimDTO $claim): void
    {
        try {
            $submission = $this->submissionRepository->findById($submissionId);
            $conversationId = $this->conversationIdFor($submissionId, $actionId, $claim, $submission);
        } catch (TransientApiFailure $e) {
            $this->retryOrFail($submissionId, $actionId, $claim, $e);

            return;
        } catch (PermanentApiFailure|AbstractDataException $e) {
            $this->failPermanently($submissionId, $actionId, $claim, $e);

            return;
        }

        $completed = $this->actionRepository->completeClaim($actionId, $claim->token, $conversationId);

        if ($this->claimHeld($completed, $submissionId, $actionId)) {
            $this->notifyProcessed($submission, $submissionId, $actionId, (int) $conversationId);
        }
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws UnexpectedApiResultException
     * @throws InsufficientDataException
     */
    private function conversationIdFor(string $submissionId, string $actionId, ActionClaimDTO $claim, ContactSubmission $submission): string
    {
        return $claim->externalId
            ?? $this->existingConversation->resolve($submissionId, $actionId, $claim, $submission)
            ?? (string) $this->helpScoutClient->createConversationFromCustomer($this->transformer->transform($submission));
    }

    /**
     * Rethrows when the failure handler failed the action.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws PermanentApiFailure
     * @throws AbstractDataException
     */
    private function failPermanently(string $submissionId, string $actionId, ActionClaimDTO $claim, PermanentApiFailure|AbstractDataException $e): void
    {
        if ($this->failureHandler->execute($submissionId, $actionId, $claim->token, $e->getMessage())) {
            throw $e;
        }
    }

    /**
     * Returns the action to `pending` and rethrows with the row's retry delay, so the job
     * releases on the same schedule as `next_attempt_at`; the last attempt fails instead.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function retryOrFail(string $submissionId, string $actionId, ActionClaimDTO $claim, TransientApiFailure $e): void
    {
        $attempts = new ActionAttempts($claim->attempts);

        if ($attempts->isLast()) {
            $this->failureHandler->execute($submissionId, $actionId, $claim->token, $e->getMessage());

            return;
        }

        $delay = $attempts->retryDelay($e->retryAfter);
        $returned = $this->actionRepository->returnToPending($actionId, $claim->token, $delay);

        if ($this->claimHeld($returned, $submissionId, $actionId)) {
            throw new ExternalServiceUnavailableException($e->serviceName, $delay, $e);
        }
    }

    /**
     * `$written` is a claim-holder write's result; `false` means another worker or the sweep took the row.
     */
    private function claimHeld(bool $written, string $submissionId, string $actionId): bool
    {
        if (! $written) {
            $this->logger->warning('Contact submission action claim lost', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
            ]);
        }

        return $written;
    }

    private function notifyProcessed(
        ContactSubmission $submission,
        string $submissionId,
        string $actionId,
        int $conversationId,
    ): void {
        $this->eventDispatcher->dispatch(new ContactFormProcessedEvent(
            submissionId: $submissionId,
            conversationId: IntId::from($conversationId),
            customerName: $submission->form->name,
            customerEmail: $submission->form->email,
        ));

        $this->emailValidationNote->addIfInvalid($submission->form->email, $conversationId);

        $this->logger->info('Contact submission processed', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'conversation_id' => $conversationId,
        ]);
    }
}
