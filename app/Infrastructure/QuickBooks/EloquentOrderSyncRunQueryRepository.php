<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRunQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncRunViewModel;
use Illuminate\Database\Eloquent\Builder;
use Override;

final readonly class EloquentOrderSyncRunQueryRepository implements OrderSyncRunQueryRepositoryInterface
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
    public function paginate(PageRequest $page): PaginatedList
    {
        return $this->eloquentGateway->paginate(
            modelClass: OrderSyncRunViewModel::class,
            scope: static function (Builder $q): void {
                $q->latest('requested_at')->latest('id');
            },
            relations: [],
            mapper: static fn(OrderSyncRunViewModel $row): OrderSyncRunDTO => $row->toDomain(),
            perPage: $page->perPage,
            page: $page->page,
        );
    }
}
