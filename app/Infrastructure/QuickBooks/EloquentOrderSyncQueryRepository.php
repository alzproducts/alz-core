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
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncStateViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Override;

final readonly class EloquentOrderSyncQueryRepository implements OrderSyncQueryRepositoryInterface
{
    private const string LEASE_LIVE_SQL = "COALESCE(status = 'processing' AND lease_expires_at > now(), false) AS lease_live";

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
    public function statesFor(string $realmId, array $references): array
    {
        if ($references === []) {
            return [];
        }

        /** @var Collection<int, OrderSyncStateViewModel> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): Collection => OrderSyncStateViewModel::query()
                ->whereKey($references)
                ->where('realm_id', $realmId)
                ->get(['reference', 'status', 'doc_type', 'qbo_doc_number', 'qbo_txn_id', 'last_error_code', new Expression(self::LEASE_LIVE_SQL)]),
        );

        $states = [];
        foreach ($rows as $row) {
            $states[$row->reference] = $row->toDomain();
        }

        return $states;
    }

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
