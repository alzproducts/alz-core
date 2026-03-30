<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\Enums\WarehouseScope;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Contract for Linnworks purchase-order-related SQL queries.
 *
 * Uses the Dashboards/ExecuteCustomScriptQuery endpoint for direct
 * database access, bypassing API pagination and date limits.
 *
 * @template-pattern Application Contract Interface
 */
interface PurchaseDashboardsClientInterface
{
    /**
     * Retrieve purchase order IDs filtered by status.
     *
     * @param list<PurchaseOrderStatus> $statuses At least one status required
     *
     * @return list<Guid> Purchase order IDs ordered by DateOfPurchase ASC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderIdsByStatus(
        array $statuses,
        WarehouseScope $warehouseScope = WarehouseScope::AnyWarehouse,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;
}
