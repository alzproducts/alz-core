<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\Product\Commands\UpdateRetailPriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for per-SKU extra product data (RRP, etc.).
 */
interface ProductExtraDataRepositoryInterface
{
    /**
     * Bulk upsert RRP values for multiple SKUs.
     *
     * Zero-value RRP commands are stored as null (clearing the RRP).
     * Uses a single DB pass — if it fails, all SKUs in the batch fail.
     *
     * @param list<UpdateRetailPriceCommand> $commands Per-SKU RRP updates
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertRrpBulk(array $commands): void;
}
