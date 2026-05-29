<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\ContactSubmission\DTOs\ContactSubmissionListItemDTO;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\ContactSubmission\ValueObjects\PotentialConversionStage;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
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

    /**
     * Fetch the workflow stage of a single dashboard row by its source id (form submission id or
     * call id).
     *
     * Reads the unified view, so it resolves rows from either source — unlike the source-specific
     * write repositories. Used by the annotation/dismiss/no-quote use cases for existence checks
     * and stage gating.
     *
     * @throws RecordNotFoundException When no row exists for the id
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findStageById(string $sourceId): PotentialConversionStage;
}
