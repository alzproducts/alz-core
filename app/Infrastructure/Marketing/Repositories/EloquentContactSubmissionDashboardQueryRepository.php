<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\ContactSubmission\Queries\ContactSubmissionListQueryParams;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Marketing\Models\ContactSubmissionDashboardViewModel;
use App\Infrastructure\Marketing\Queries\ContactSubmissionDashboardQuery;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Pagination + mapping over marketing.contact_submission_dashboard_view.
 *
 * All join shape and column derivation lives in the view. All filter predicates
 * live in {@see ContactSubmissionDashboardQuery}. This repository is the thin
 * orchestration shim between them.
 */
final readonly class EloquentContactSubmissionDashboardQueryRepository implements ContactSubmissionDashboardQueryRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function paginate(ContactSubmissionListQueryParams $query): PaginatedList
    {
        $filters = $query->filters;

        return $this->eloquentGateway->paginate(
            modelClass: ContactSubmissionDashboardViewModel::class,
            scope: static function (Builder $q) use ($filters): void {
                ContactSubmissionDashboardQuery::apply($q, $filters);
                $q->orderByDesc('created_at');
            },
            relations: [],
            mapper: static fn(ContactSubmissionDashboardViewModel $model) => $model->toDomain(),
            perPage: $query->pagination->perPage,
            page: $query->pagination->page,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function paginateView(ContactSubmissionView $view, PageRequest $pagination): PaginatedList
    {
        return $this->eloquentGateway->paginate(
            modelClass: ContactSubmissionDashboardViewModel::class,
            scope: static function (Builder $q) use ($view): void {
                ContactSubmissionDashboardQuery::applyView($q, $view);
            },
            relations: [],
            mapper: static fn(ContactSubmissionDashboardViewModel $model) => $model->toDomain(),
            perPage: $pagination->perPage,
            page: $pagination->page,
        );
    }
}
