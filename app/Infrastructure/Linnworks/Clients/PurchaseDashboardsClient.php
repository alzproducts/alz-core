<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Linnworks\Enums\PurchaseOrderIdScope;
use App\Application\Linnworks\Queries\PurchaseOrderIdQueryParams;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\AllPurchaseOrderIdsQuery;
use App\Infrastructure\Linnworks\Queries\FastPurchaseOrderIdsQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderHeadersBatchQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderIdsByDateRangeQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderItemsBatchQuery;
use Webmozart\Assert\Assert;

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
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderIds(PurchaseOrderIdQueryParams $query): array
    {
        $infraQuery = match ($query->scope) {
            PurchaseOrderIdScope::FastSync => $this->buildFastSyncQuery($query),
            PurchaseOrderIdScope::DateRange => $this->buildDateRangeQuery($query),
            PurchaseOrderIdScope::All => new AllPurchaseOrderIdsQuery(),
        };

        /** @var list<Guid> */
        return $this->dashboardsClient->execute($infraQuery);
    }

    private function buildFastSyncQuery(PurchaseOrderIdQueryParams $query): FastPurchaseOrderIdsQuery
    {
        Assert::notNull($query->createdSince);

        return new FastPurchaseOrderIdsQuery($query->createdSince, $query->includeDeliveredToday);
    }

    private function buildDateRangeQuery(PurchaseOrderIdQueryParams $query): PurchaseOrderIdsByDateRangeQuery
    {
        Assert::notNull($query->from);
        Assert::notNull($query->to);

        return new PurchaseOrderIdsByDateRangeQuery($query->from, $query->to);
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
