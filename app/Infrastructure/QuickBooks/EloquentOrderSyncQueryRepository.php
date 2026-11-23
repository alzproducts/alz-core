<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\FailedOrderSyncViewModel;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncSourceViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Override;

final readonly class EloquentOrderSyncQueryRepository implements OrderSyncQueryRepositoryInterface
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
    public function paginateFailed(string $realmId, PageRequest $page): PaginatedList
    {
        $rows = $this->eloquentGateway->paginate(
            modelClass: FailedOrderSyncViewModel::class,
            scope: static function (Builder $q) use ($realmId): void {
                $q->where('realm_id', $realmId)
                    ->where('status', OrderSyncRowStatus::Failed->value)
                    ->latest('last_attempted_at')
                    ->latest('reference');
            },
            relations: [],
            mapper: static fn(FailedOrderSyncViewModel $row): FailedOrderSyncViewModel => $row,
            perPage: $page->perPage,
            page: $page->page,
        );

        $placedAt = $this->placedAtByReference(\array_map(
            static fn(FailedOrderSyncViewModel $row): int => $row->reference,
            $rows->items,
        ));

        return PaginatedList::fromPage(
            items: \array_map(
                static fn(FailedOrderSyncViewModel $row) => $row->toDomain($placedAt[$row->reference] ?? null),
                $rows->items,
            ),
            total: $rows->total,
            perPage: $rows->perPage,
            currentPage: $rows->currentPage,
        );
    }

    /**
     * @param list<int> $references
     *
     * @return array<int, CarbonImmutable>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function placedAtByReference(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $placedAt = [];
        foreach ($this->eloquentGateway->findMany(OrderSyncSourceViewModel::class, 'reference', $references) as $row) {
            if ($row instanceof OrderSyncSourceViewModel) {
                $placedAt[$row->reference] = $row->order_placed_at;
            }
        }

        return $placedAt;
    }
}
