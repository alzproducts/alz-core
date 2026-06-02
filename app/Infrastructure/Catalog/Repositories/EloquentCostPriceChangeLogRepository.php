<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\CostPriceChangeLogRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Models\CostPriceChangeLogModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Write-only repository for the `catalog.cost_price_changes` audit log.
 *
 * Bulk-inserts via {@see EloquentGateway::insertMany}. Rows omit `changed_at` so the DB
 * `useCurrent()` default fills it ({@see CostPriceChangeLogModel}); `id` is set by `HasUuids`.
 */
final readonly class EloquentCostPriceChangeLogRepository implements CostPriceChangeLogRepositoryInterface
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
    public function record(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $rows = \array_map(CostPriceChangeLogModel::attributesFromDomain(...), $changes);

        $this->eloquentGateway->insertMany(CostPriceChangeLogModel::class, $rows);
    }
}
