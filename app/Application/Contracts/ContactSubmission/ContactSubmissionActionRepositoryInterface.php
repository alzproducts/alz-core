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
}
