<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Queries\ContactSubmissionListQueryParams;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmissionListItem;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

/**
 * Read use case for the staff contact-submissions dashboard.
 *
 * Thin orchestrator: logs the request, delegates to the dashboard query repository,
 * and logs the resulting page size. No business logic — the SQL projection lives
 * in the repository.
 */
final readonly class ListContactSubmissionsUseCase
{
    public function __construct(
        private ContactSubmissionDashboardQueryRepositoryInterface $dashboardQueryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<ContactSubmissionListItem>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(ContactSubmissionListQueryParams $query): PaginatedList
    {
        $this->logger->info('Listing contact submissions', [
            'page' => $query->pagination->page,
            'per_page' => $query->pagination->perPage,
            'filters' => \array_keys($query->filters),
        ]);

        $result = $this->dashboardQueryRepository->paginate($query);

        $this->logger->info('Listed contact submissions', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
