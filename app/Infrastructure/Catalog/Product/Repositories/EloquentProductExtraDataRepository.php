<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Repositories;

use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Models\ProductExtraDataModel;
use App\Infrastructure\Persistence\EloquentGateway;

/**
 * Eloquent implementation of per-SKU extra data repository.
 *
 * Uses EloquentGateway for proper exception translation and retry handling.
 */
final readonly class EloquentProductExtraDataRepository implements ProductExtraDataRepositoryInterface
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
    public function upsertRrpBulk(array $commands): void
    {
        if ($commands === []) {
            return;
        }

        $rows = \array_map(ProductExtraDataModel::attributesFromDomain(...), $commands);

        $this->eloquentGateway->upsertMany(
            modelClass: ProductExtraDataModel::class,
            rows: $rows,
            uniqueBy: ['sku'],
            update: ['rrp', 'updated_at'],
        );
    }
}
