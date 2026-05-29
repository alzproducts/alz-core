<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\PotentialConversion;

use App\Application\Conversion\PotentialConversion\Commands\UpsertAnnotationCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Write access to `marketing.potential_conversion_annotations`, keyed by `source_id` — a bare
 * UUID that is either a contact submission id or a call id (globally unique across both sources).
 */
interface PotentialConversionAnnotationRepositoryInterface
{
    /**
     * Upsert the annotation row for a potential-conversion source using partial-update semantics.
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
     * action row exists for the source (checked against both form and call action tables).
     *
     * The atomic guard closes the TOCTOU race between the use-case pre-check and this write —
     * if a lead action is inserted between the two, this call affects 0 rows (which the HTTP
     * layer treats as a 204 no-op; the dismiss has been race-resolved). Idempotent: repeat calls
     * never overwrite the original timestamp.
     *
     * Bypasses {@see UpsertAnnotationCommand} because the predicate references separate tables
     * (`customer_service.contact_submission_actions`, `customer_service.call_tracking_actions`) —
     * implemented via raw SQL.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markDismissed(string $sourceId): void;

    /**
     * Clear `is_potential_quote` on the annotation row, only when it is currently `true` AND no
     * `quote_issued` action row exists for the source.
     *
     * The atomic guard closes the TOCTOU race between the use-case pre-check and the
     * `POST /conversions/quote` write — if a quote action arrives concurrently, this call
     * affects 0 rows (HTTP 204 no-op; the awaiting-quote item already left the queue). Plain
     * UPDATE (no INSERT branch) because Stage 2's lead use case writes the annotation row up
     * front, so the row is guaranteed to exist for any source that has reached awaiting-quote.
     *
     * Form-only guard: `markNoQuoteExpected` is a form-only endpoint (calls have no quote
     * tracking yet); the use case rejects call rows before this is reached.
     *
     * @return bool `true` when the row was updated, `false` when the atomic guard blocked the
     *              write (concurrent quote action won the race — caller should log and treat as no-op)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markNoQuoteExpected(string $sourceId): bool;
}
