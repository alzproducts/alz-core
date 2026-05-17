<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Queries;

use App\Domain\ContactSubmission\Enums\ConversionStatus;
use DateTimeImmutable;

/**
 * Typed filters for the staff contact-submissions dashboard.
 *
 * Every property is nullable; null means "filter not applied". `dateFrom`/`dateTo`
 * are the half-open `[from, to)` bounds — the Presentation layer is responsible for
 * adding one day to a `Y-m-d` end-of-day filter before passing it in.
 *
 * `isPotentialQuote` is two-state at this layer: `true` matches rows where the
 * annotation column is `true`; `false` matches rows where it is `false`; `null`
 * applies no filter. Rows with no annotation (column IS NULL) are excluded from
 * both `true` and `false` matches.
 */
final readonly class ContactSubmissionDashboardFiltersParams
{
    public function __construct(
        public ?bool $hasGclid = null,
        public ?bool $isPotentialQuote = null,
        public ?DateTimeImmutable $dateFrom = null,
        public ?DateTimeImmutable $dateTo = null,
        public ?ConversionStatus $conversionStatus = null,
    ) {}
}
