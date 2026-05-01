<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface SkuPopularityRankingSnapshotRepositoryInterface
{
    /**
     * Writes one row per SKU into `catalog.sku_popularity_snapshots`
     * by executing:
     *   INSERT INTO catalog.sku_popularity_snapshots (...columns...)
     *   SELECT CURRENT_DATE, v.* FROM catalog.sku_popularity_ranking v
     *
     * @return int Number of rows written (one per SKU in the catalog)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException          If a snapshot for CURRENT_DATE already exists
     * @throws ExternalServiceUnavailableException
     */
    public function writeSnapshotForToday(): int;
}
