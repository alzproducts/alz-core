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
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
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

    /**
     * Retrieve purchase order IDs for fast sync (OPEN/PENDING/PARTIAL + today's DELIVERED).
     *
     * Returns POs at the default warehouse (OurWarehouse) created since the given date,
     * plus optionally DELIVERED POs with a delivery date of today.
     *
     * @return list<Guid> Purchase order IDs ordered by DateOfPurchase ASC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getFastSyncPurchaseOrderIds(
        DateTimeImmutable $createdSince,
        bool $includeDeliveredToday = true,
    ): array;

    /**
     * Retrieve purchase order IDs where DateOfDelivery or DateOfPurchase falls in range.
     *
     * Returns all POs (any status, any warehouse) for the given date window.
     * Used for normal daily sync.
     *
     * @return list<Guid> Purchase order IDs ordered by DateOfPurchase ASC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderIdsByDateRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;

    /**
     * Retrieve all purchase order IDs with no filters.
     *
     * Returns every PO ID — all statuses, all warehouses. Use for full backfill only.
     *
     * @return list<Guid> Purchase order IDs ordered by DateOfPurchase ASC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getAllPurchaseOrderIds(): array;

    /**
     * Batch-fetch purchase order headers with computed counts.
     *
     * Returns complete headers with lineCount, deliveredLinesCount, and noteCount
     * calculated via SQL subqueries. Keyed by purchase ID for easy assembly.
     *
     * @param list<Guid> $purchaseIds
     *
     * @return array<string, array{header: PurchaseOrderHeader, noteCount: int}>
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderHeadersBatch(array $purchaseIds): array;

    /**
     * Batch-fetch purchase order items grouped by parent purchase ID.
     *
     * @param list<Guid> $purchaseIds
     *
     * @return array<string, list<PurchaseOrderItem>>
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderItemsBatch(array $purchaseIds): array;
}
