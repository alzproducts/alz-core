<?php

declare(strict_types=1);

namespace App\Application\Contracts\Operations;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Operations\ValueObjects\PriceSnapshot;

/**
 * Repository for SCD2 price period tracking.
 *
 * Records price history by closing the current period and opening a new one
 * in a single transaction. The partial unique index on (sku WHERE effective_to IS NULL)
 * guarantees exactly one "current" row per SKU.
 */
interface PricePeriodRepositoryInterface
{
    /**
     * Record a price change for a SKU using SCD2 mechanics.
     *
     * Atomically: closes the current period (UPDATE effective_to = now())
     * and inserts a new period with the given pricing. If no current period
     * exists (first price for this SKU), only the INSERT runs.
     *
     * @throws DatabaseOperationFailedException On permanent database failure
     * @throws DuplicateRecordException On unique constraint violation
     * @throws ExternalServiceUnavailableException On transient database failure (retryable)
     */
    public function recordPriceChange(PriceSnapshot $snapshot): void;
}
