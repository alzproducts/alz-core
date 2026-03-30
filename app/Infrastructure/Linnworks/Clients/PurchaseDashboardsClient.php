<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\OpenPendingPurchaseOrderIdsQuery;
use App\Infrastructure\Linnworks\Queries\PurchaseOrderIdsByDateQuery;
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
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderIdsByDate(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        bool $defaultLocationOnly = false,
    ): array {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(
            new PurchaseOrderIdsByDateQuery($from, $to, $defaultLocationOnly),
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
    public function getOpenPendingPurchaseOrderIds(bool $defaultLocationOnly = false): array
    {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(
            new OpenPendingPurchaseOrderIdsQuery($defaultLocationOnly),
        );
    }
}
