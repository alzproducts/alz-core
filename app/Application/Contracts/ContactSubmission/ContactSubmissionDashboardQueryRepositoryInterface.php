<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\ContactSubmission\DTOs\ContactSubmissionListItemDTO;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;

interface ContactSubmissionDashboardQueryRepositoryInterface
{
    /**
     * Paginate the named workflow view (filter set + sort order defined per case).
     *
     * @return PaginatedList<ContactSubmissionListItemDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginateView(ContactSubmissionView $view, PageRequest $pagination): PaginatedList;
}
