<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\DTOs\ContactSubmissionListItemDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

/**
 * Per-view read use case for the staff contact-submissions dashboard.
 *
 * Thin orchestrator: logs the request, delegates per-view filter + sort dispatching
 * to the dashboard query repository, and logs the resulting page size. The
 * {@see ContactSubmissionView} enum is the contract — each case maps to a fixed
 * WHERE + ORDER BY combination in the repository's query builder.
 */
final readonly class ListContactSubmissionsByViewUseCase
{
    public function __construct(
        private ContactSubmissionDashboardQueryRepositoryInterface $dashboardQueryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<ContactSubmissionListItemDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(ContactSubmissionView $view, PageRequest $pagination): PaginatedList
    {
        $this->logger->info('Listing contact submissions by view', [
            'view' => $view->value,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
        ]);

        $result = $this->dashboardQueryRepository->paginateView($view, $pagination);

        $this->logger->info('Listed contact submissions by view', [
            'view' => $view->value,
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
