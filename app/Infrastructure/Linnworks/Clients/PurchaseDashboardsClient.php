<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
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
use App\Infrastructure\Linnworks\Queries\AllPurchaseOrderIdsQuery;
use App\Infrastructure\Linnworks\Queries\FastPurchaseOrderIdsQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderHeadersBatchQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderIdsByDateRangeQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderIdsByStatusQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderItemsBatchQuery;
use DateTimeImmutable;

/**
 * Purchase-order-related queries via Linnworks Dashboards SQL API.
 *
 * Facade providing typed methods for the Application layer, internally
 * using query objects for self-contained SQL construction and response mapping.
 *
 * @template-pattern Infrastructure API Client Facade
 */
final readonly class PurchaseDashboardsClient implements PurchaseDashboardsClientInterface
{
    public function __construct(
        private DashboardsClient $dashboardsClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @param list<PurchaseOrderStatus> $statuses
     *
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException When query fails
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
    ): array {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(
            new PurchaseOrderIdsByStatusQuery($statuses, $warehouseScope, $from, $to),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getFastSyncPurchaseOrderIds(
        DateTimeImmutable $createdSince,
        bool $includeDeliveredToday = true,
    ): array {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(
            new FastPurchaseOrderIdsQuery($createdSince, $includeDeliveredToday),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderIdsByDateRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(
            new PurchaseOrderIdsByDateRangeQuery($from, $to),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getAllPurchaseOrderIds(): array
    {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(new AllPurchaseOrderIdsQuery());
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, array{header: PurchaseOrderHeader, noteCount: int}>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderHeadersBatch(array $purchaseIds): array
    {
        /** @var array<string, array{header: PurchaseOrderHeader, noteCount: int}> */
        return $this->dashboardsClient->execute(
            new PurchaseOrderHeadersBatchQuery($purchaseIds),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, list<PurchaseOrderItem>>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderItemsBatch(array $purchaseIds): array
    {
        /** @var array<string, list<PurchaseOrderItem>> */
        return $this->dashboardsClient->execute(
            new PurchaseOrderItemsBatchQuery($purchaseIds),
        );
    }
}
