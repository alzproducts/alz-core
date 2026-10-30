<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\ContactSubmission\DTOs\RecoverableActionDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;

interface ContactSubmissionActionRepositoryInterface
{
    /**
     * `$adPlatform = null` for HelpScout rows. Partial unique indexes scope uniqueness
     * per-platform for NOT-NULL rows and per-`action_type` for NULL rows.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function create(string $submissionId, ActionType $actionType, ?AdPlatform $adPlatform = null): string;

    /**
     * Stores the quote details so the sweeper can rebuild the quote command from the row.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function createQuoteAction(string $submissionId, AdPlatform $platform, Money $value, DateTimeImmutable $convertedAt): string;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findActionType(string $actionId): ?ActionType;

    /**
     * Moves a due `pending` row to `processing` under a new claim token, counting the attempt.
     * A row whose `next_attempt_at` is at most 5 seconds ahead counts as due.
     *
     * @return ActionClaimDTO|null null when the row is not pending, not due, or taken
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function claim(string $actionId): ?ActionClaimDTO;

    /**
     * Claim-holder transitions match `processing` rows holding `$claimToken` and clear the token;
     * `false` means the claim was lost and the row is unchanged.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function completeClaim(string $actionId, string $claimToken, string $externalId): bool;

    /**
     * @see completeClaim() for the claim-holder match
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function failClaim(string $actionId, string $claimToken, string $errorMessage): bool;

    /**
     * Returns the row to `pending`, due `$delaySeconds` from now.
     *
     * @see completeClaim() for the claim-holder match
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function returnToPending(string $actionId, string $claimToken, int $delaySeconds): bool;

    /**
     * `pending` → `failed`, for a row no worker holds.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function failUnclaimed(string $actionId, string $errorMessage): bool;

    /**
     * The subset of `$conversationIds` already recorded on another HelpScout action.
     *
     * @param list<string> $conversationIds
     *
     * @return list<string>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function linkedConversationIds(array $conversationIds, string $excludeActionId): array;

    /**
     * `processing` rows claimed before `$claimedBefore`.
     *
     * @return list<RecoverableActionDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findExpiredLeases(DateTimeImmutable $claimedBefore): array;

    /**
     * Returns an expired lease to `pending`, due now; `false` when the row no longer matches.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function releaseExpiredLease(string $actionId, DateTimeImmutable $claimedBefore): bool;

    /**
     * `pending` rows whose `next_attempt_at` (else `created_at`) is before `$dueBefore`.
     *
     * @return list<RecoverableActionDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findOverduePending(DateTimeImmutable $dueBefore): array;

    /**
     * Sets `next_attempt_at` to now on a row still overdue; `false` when it no longer matches.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deferOverduePending(string $actionId, DateTimeImmutable $dueBefore): bool;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getStatus(string $actionId): ?ActionStatus;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function hasCompletedAction(string $submissionId, ActionType $actionType): bool;

    /**
     * Aggregates per-platform rows into one status per ADR-0002 priority:
     * `completed` > `failed` > `processing` > `pending` > `null` (no rows).
     *
     * Distinguishes "no row" (`null`) from "exists but not completed" —
     * which {@see hasCompletedAction} cannot.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findActionStatus(string $submissionId, ActionType $actionType): ?ActionStatus;
}
