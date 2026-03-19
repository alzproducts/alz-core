<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;

/**
 * Repository for Linnworks order persistence.
 *
 * Sync strategy: upsert by linnworks_order_id (Linnworks GUID).
 * No line items — order-level data only (deferred to follow-up PR).
 *
 * @extends RepositoryWriteInterface<LinnworksOrder>
 */
interface LinnworksOrderRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Bulk upsert orders using high-performance batch operations.
     *
     * @param list<LinnworksOrder> $orders Orders to persist
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveOrdersBulk(array $orders): SaveManyResult;
}
