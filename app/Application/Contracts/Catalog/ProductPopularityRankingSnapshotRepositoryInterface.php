<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface ProductPopularityRankingSnapshotRepositoryInterface
{
    /**
     * Writes one row per product into `catalog.product_popularity_snapshots`
     * by executing:
     *   INSERT INTO catalog.product_popularity_snapshots (...columns...)
     *   SELECT CURRENT_DATE, v.* FROM catalog.product_popularity_ranking v
     *
     * @return int Number of rows written (one per product in the catalog)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException          If a snapshot for CURRENT_DATE already exists
     * @throws ExternalServiceUnavailableException
     */
    public function writeSnapshotForToday(): int;
}
