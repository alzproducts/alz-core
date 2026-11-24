<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncSourceViewModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;

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
