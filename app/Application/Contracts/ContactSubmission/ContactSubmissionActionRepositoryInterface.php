<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\Contracts\AsyncActionRepositoryInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * @extends AsyncActionRepositoryInterface<ActionStatus>
 */
interface ContactSubmissionActionRepositoryInterface extends AsyncActionRepositoryInterface
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
