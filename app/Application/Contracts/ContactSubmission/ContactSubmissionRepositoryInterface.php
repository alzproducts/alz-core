<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for contact form submissions.
 *
 * Handles persistence of immutable submission snapshots to public_ingest schema.
 * Insert-only pattern (no updates) - submissions are immutable audit records.
 */
interface ContactSubmissionRepositoryInterface
{
    /**
     * Persist a new contact submission.
     *
     * @return string UUID of the created record
     *
     * @throws DatabaseOperationFailedException On insert failure
     * @throws DuplicateRecordException On unique constraint violation
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function save(ContactSubmission $submission): string;

    /**
     * Find a submission by ID.
     *
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function findById(string $id): ?ContactSubmission;
}
