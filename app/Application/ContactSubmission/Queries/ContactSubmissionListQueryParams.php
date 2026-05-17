<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Queries;

use App\Domain\ContactSubmission\Enums\ContactSubmissionFilterField;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;

/**
 * Query parameters for the staff contact-submissions dashboard.
 *
 * Filters are keyed by {@see ContactSubmissionFilterField} string values. Sort order
 * is fixed at `created_at desc` — there is no `sort` parameter.
 */
final readonly class ContactSubmissionListQueryParams
{
    /**
     * @param array<value-of<ContactSubmissionFilterField>, mixed> $filters
     */
    public function __construct(
        public PageRequest $pagination,
        public array $filters = [],
    ) {}
}
