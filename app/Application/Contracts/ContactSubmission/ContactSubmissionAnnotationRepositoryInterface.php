<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface ContactSubmissionAnnotationRepositoryInterface
{
    /**
     * Upsert the annotation row for a contact submission using partial-update semantics.
     *
     * Only columns referenced by `$command->valuesToSet` or `$command->columnsToClear` participate
     * in the ON CONFLICT DO UPDATE; untouched columns retain their previous values.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsert(UpsertAnnotationCommand $command): void;

    /**
     * Stamp `dismissed_at` on the annotation row, idempotently and only when no `lead_received`
     * action row exists for the submission.
     *
     * The atomic guard closes the TOCTOU race between the use-case pre-check and this write —
     * if a lead action is inserted between the two, this call affects 0 rows (which the HTTP
     * layer treats as a 204 no-op; the dismiss has been race-resolved). Idempotent: repeat calls
     * never overwrite the original timestamp.
     *
     * Bypasses {@see UpsertAnnotationCommand} because the predicate references a separate table
     * (`customer_service.contact_submission_actions`) — implemented via raw SQL.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markDismissed(string $submissionId): void;

    /**
     * Clear `is_potential_quote` on the annotation row, only when it is currently `true` AND no
     * `quote_issued` action row exists for the submission.
     *
     * The atomic guard closes the TOCTOU race between the use-case pre-check and the
     * `POST /conversions/quote` write — if a quote action arrives concurrently, this call
     * affects 0 rows (HTTP 204 no-op; the awaiting-quote item already left the queue). Plain
     * UPDATE (no INSERT branch) because Stage 2's lead use case writes the annotation row up
     * front, so the row is guaranteed to exist for any submission that has reached awaiting-quote.
     *
     * @return bool `true` when the row was updated, `false` when the atomic guard blocked the
     *              write (concurrent quote action won the race — caller should log and treat as no-op)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markNoQuoteExpected(string $submissionId): bool;
}
