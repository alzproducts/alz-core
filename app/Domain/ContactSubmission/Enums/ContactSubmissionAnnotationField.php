<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

/**
 * Mutable columns of `marketing.potential_conversion_annotations`.
 *
 * Backing values are the DB column names so cases can be spread directly into upsert
 * attribute arrays. All three columns are nullable in the schema, so every case is
 * clearable; {@see isClearable()} exists to satisfy the merge-patch command invariant.
 */
enum ContactSubmissionAnnotationField: string
{
    case IsPotentialQuote = 'is_potential_quote';
    case Notes = 'notes';
    case QuotedAt = 'quoted_at';

    /**
     * Per-case NOT NULL constraint signal. All current columns are nullable; when a
     * future non-nullable column is added, convert this to a `match` with an explicit
     * `false` arm for that case.
     */
    public function isClearable(): bool
    {
        return true;
    }
}
