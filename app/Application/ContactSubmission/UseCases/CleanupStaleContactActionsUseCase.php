<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\DTOs\RecoverableActionDTO;
use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Recovers contact submission actions no worker is progressing (ADR-0012): expired
 * leases return to `pending`, and overdue `pending` rows are re-dispatched. Each row
 * is re-dispatched to its own pipeline only after a conditional write that sets
 * `next_attempt_at` to now, so a row re-dispatches at most once per overdue window.
 *
 * Batch-processing pattern: a row whose write fails is logged and skipped.
 */
final readonly class CleanupStaleContactActionsUseCase
{
    private const int LEASE_SECONDS = 360;

    private const int OVERDUE_SECONDS = 600;

    public function __construct(
        private ContactSubmissionActionRepositoryInterface $repository,
        private ContactFormDispatcherInterface $contactFormDispatcher,
        private ConversionDispatcherInterface $conversionDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException When a recoverable-row query fails
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function execute(): void
    {
        $this->logger->info('Sweeping stale contact submission actions');

        $now = new DateTimeImmutable();
        $releasedCount = $this->releaseExpiredLeases($now->modify(\sprintf('-%d seconds', self::LEASE_SECONDS)));
        $overdueCount = $this->redispatchOverduePending($now->modify(\sprintf('-%d seconds', self::OVERDUE_SECONDS)));

        $this->logger->info('Completed stale contact action sweep', [
            'released_count' => $releasedCount,
            'overdue_count' => $overdueCount,
        ]);
    }

    /**
     * @return int rows released and re-dispatched
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function releaseExpiredLeases(DateTimeImmutable $claimedBefore): int
    {
        $count = 0;
        foreach ($this->repository->findExpiredLeases($claimedBefore) as $action) {
            try {
                if ($this->repository->releaseExpiredLease($action->actionId, $claimedBefore)) {
                    $this->logger->warning('Released expired contact action lease', self::context($action));
                    $this->dispatch($action);
                    $count++;
                }
            } catch (DatabaseOperationFailedException $e) {
                $this->logWriteFailure($action, $e);
            }
        }

        return $count;
    }

    /**
     * @return int rows re-dispatched
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function redispatchOverduePending(DateTimeImmutable $dueBefore): int
    {
        $count = 0;
        foreach ($this->repository->findOverduePending($dueBefore) as $action) {
            try {
                if ($this->repository->deferOverduePending($action->actionId, $dueBefore)) {
                    $this->logger->warning('Re-dispatching overdue pending contact action', self::context($action));
                    $this->dispatch($action);
                    $count++;
                }
            } catch (DatabaseOperationFailedException $e) {
                $this->logWriteFailure($action, $e);
            }
        }

        return $count;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function dispatch(RecoverableActionDTO $action): void
    {
        match ($action->actionType) {
            ActionType::HelpScout => $this->contactFormDispatcher->dispatchContactSubmissionProcessing(
                $action->submissionId,
                $action->actionId,
            ),
            ActionType::LeadReceived => $this->dispatchLeadConversion($action),
            ActionType::QuoteIssued => $this->dispatchQuoteConversion($action),
        };
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function dispatchLeadConversion(RecoverableActionDTO $action): void
    {
        if ($action->adPlatform === null) {
            $this->failUndispatchable($action, 'Conversion action has no ad platform');

            return;
        }

        $this->conversionDispatcher->dispatchLeadConversion(new LeadConversionCommand(
            Uuid::fromTrusted($action->submissionId),
            Uuid::fromTrusted($action->actionId),
            $action->adPlatform,
        ));
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function dispatchQuoteConversion(RecoverableActionDTO $action): void
    {
        if ($action->adPlatform === null || $action->quoteValue === null || $action->quoteConvertedAt === null) {
            $this->failUndispatchable($action, 'Quote action has no stored ad platform, value or converted-at');

            return;
        }

        $this->conversionDispatcher->dispatchQuoteConversion(new QuoteConversionCommand(
            submissionId: Uuid::fromTrusted($action->submissionId),
            actionId: Uuid::fromTrusted($action->actionId),
            value: $action->quoteValue,
            convertedAt: $action->quoteConvertedAt,
            platform: $action->adPlatform,
        ));
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function failUndispatchable(RecoverableActionDTO $action, string $reason): void
    {
        $this->logger->error('Cannot rebuild contact action command from its row — failing it', [
            ...self::context($action),
            'reason' => $reason,
        ]);

        $this->repository->failUnclaimed($action->actionId, $reason);
    }

    private function logWriteFailure(RecoverableActionDTO $action, DatabaseOperationFailedException $e): void
    {
        $this->logger->error('Failed to recover stale contact action', [
            ...self::context($action),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private static function context(RecoverableActionDTO $action): array
    {
        return [
            'action_id' => $action->actionId,
            'submission_id' => $action->submissionId,
            'action_type' => $action->actionType->value,
            'ad_platform' => $action->adPlatform?->value,
        ];
    }
}
