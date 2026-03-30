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
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderIdsByStatusQuery;
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
}
