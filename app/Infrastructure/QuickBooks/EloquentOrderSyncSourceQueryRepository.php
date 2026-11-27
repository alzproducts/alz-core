<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\CatchUpCandidateWindowDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncSourceViewModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Override;
use stdClass;

final readonly class EloquentOrderSyncSourceQueryRepository implements OrderSyncSourceQueryRepositoryInterface
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
    public function findByReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        // intentional: whereKey inlines the integers; a bound one above int4 would fail the whole lookup.
        /** @var Collection<int, OrderSyncSourceViewModel> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): Collection => OrderSyncSourceViewModel::query()->whereKey($references)->get(),
        );

        $orders = [];
        foreach ($rows as $row) {
            $orders[$row->reference] = $row->toDomain();
        }

        return $orders;
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function countPlacedIn(DateTimeImmutable $from, DateTimeImmutable $before): int
    {
        return $this->eloquentGateway->query(static fn(): int => self::placedIn($from, $before)->toBase()->count());
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function referencesPlacedIn(DateTimeImmutable $from, DateTimeImmutable $before): array
    {
        /** @var Collection<int, OrderSyncSourceViewModel> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): Collection => self::placedIn($from, $before)->oldest('reference')->get(['reference']),
        );

        $references = [];
        foreach ($rows as $row) {
            $references[] = $row->reference;
        }

        return $references;
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function catchUpCandidates(CatchUpCandidateWindowDTO $window): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->eloquentGateway->query(static fn(): array => self::placedIn($window->from, $window->before)
            ->toBase()
            ->leftJoin('quickbooks.order_syncs as s', static function (JoinClause $join) use ($window): void {
                $join->on('s.reference', '=', 'order_sync_source.reference')->where('s.realm_id', '=', $window->realmId);
            })
            ->whereRaw("order_sync_source.lifecycle_status IS DISTINCT FROM 'cancelled'")
            ->where(static fn(QueryBuilder $q): QueryBuilder => self::unsyncedOrRecentItemMissing($q, $window->itemMissingSince))
            ->orderBy('order_sync_source.reference')
            ->get(['order_sync_source.reference'])
            ->all());

        $references = [];
        foreach (OrderSyncSourceViewModel::hydrate($rows) as $row) {
            $references[] = $row->reference;
        }

        return $references;
    }

    private static function unsyncedOrRecentItemMissing(QueryBuilder $q, DateTimeImmutable $itemMissingSince): QueryBuilder
    {
        return $q->whereNull('s.reference')
            ->orWhereNotIn('s.status', ['synced', 'failed'])
            ->orWhere(static function (QueryBuilder $failed) use ($itemMissingSince): void {
                $failed->where('s.status', 'failed')
                    ->where('s.last_error_code', SyncReasonCode::ItemMissing->value)
                    ->where('order_sync_source.order_placed_at', '>=', self::instant($itemMissingSince));
            });
    }

    /**
     * @return Builder<OrderSyncSourceViewModel>
     */
    private static function placedIn(DateTimeImmutable $from, DateTimeImmutable $before): Builder
    {
        return OrderSyncSourceViewModel::query()
            ->where('order_placed_at', '>=', self::instant($from))
            ->where('order_placed_at', '<', self::instant($before));
    }

    /**
     * intentional: the framework binds a date object without its offset, which reads a London instant as UTC.
     */
    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
