<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\ContactSubmission\ValueObjects\PotentialConversionStage;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Marketing\Models\ContactSubmissionDashboardViewModel;
use App\Infrastructure\Marketing\Queries\ContactSubmissionDashboardQuery;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Database\Eloquent\Builder;
use Override;

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

    /**
     * {@inheritDoc}
     *
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findStageById(string $sourceId): PotentialConversionStage
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: ContactSubmissionDashboardViewModel::class,
            column: 'id',
            value: $sourceId,
            entityTypeName: 'PotentialConversion',
            mapper: static fn(ContactSubmissionDashboardViewModel $model) => $model->toStage(),
        );
    }
}
