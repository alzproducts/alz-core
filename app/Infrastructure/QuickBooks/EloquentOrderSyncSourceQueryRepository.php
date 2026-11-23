<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncSourceViewModel;
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
}
