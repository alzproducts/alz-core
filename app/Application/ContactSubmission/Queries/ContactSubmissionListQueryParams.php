<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Queries;

use App\Domain\Shared\Pagination\ValueObjects\PageRequest;

/**
 * Query parameters for the staff contact-submissions dashboard.
 *
 * Sort order is fixed at `created_at desc` — there is no `sort` parameter.
 */
final readonly class ContactSubmissionListQueryParams
{
    public function __construct(
        public PageRequest $pagination,
        public ContactSubmissionDashboardFiltersParams $filters = new ContactSubmissionDashboardFiltersParams(),
    ) {}
}
