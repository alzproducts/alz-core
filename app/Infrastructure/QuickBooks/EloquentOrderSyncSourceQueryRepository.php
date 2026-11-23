<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncSourceViewModel;
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

        $orders = [];
        foreach ($this->eloquentGateway->findMany(OrderSyncSourceViewModel::class, 'reference', $references) as $row) {
            if ($row instanceof OrderSyncSourceViewModel) {
                $orders[$row->reference] = $row->toDomain();
            }
        }

        return $orders;
    }
}
