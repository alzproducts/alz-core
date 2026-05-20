<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\Contracts\AsyncActionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for contact submission processing actions.
 *
 * Extends AsyncActionRepositoryInterface with contact-submission-specific
 * creation and status methods. Manages mutable processing state in
 * customer_service schema.
 *
 * @extends AsyncActionRepositoryInterface<ActionStatus>
 */
interface ContactSubmissionActionRepositoryInterface extends AsyncActionRepositoryInterface
{
    /**
     * Create a new action record for a submission.
     *
     * @return string UUID of the created action record
     *
     * @throws DatabaseOperationFailedException On insert failure
     * @throws DuplicateRecordException If action type already exists for submission
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function create(string $submissionId, ActionType $actionType): string;

    /**
     * Get the current status of an action.
     *
     * Narrows the return type from the generic interface.
     *
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function getStatus(string $actionId): ?ActionStatus;

    /**
     * Check whether a submission has a completed action of the given type.
     *
     * Used for sequential enforcement — e.g. a QuoteIssued action requires a
     * completed LeadReceived action on the same submission.
     *
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function hasCompletedAction(string $submissionId, ActionType $actionType): bool;

    /**
     * Look up the current status of a submission's action by type.
     *
     * Distinguishes "no row exists" (`null`) from "exists but not completed" — a distinction
     * {@see hasCompletedAction} cannot express. Used by workflow stage guards (e.g. dismiss
     * requires no `lead_received` row of any status).
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function findActionStatus(string $submissionId, ActionType $actionType): ?ActionStatus;
}
