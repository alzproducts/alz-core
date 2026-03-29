<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\ProcessedOrderIdsQuery;
use DateTimeImmutable;

/**
 * Order-related queries via Linnworks Dashboards SQL API.
 *
 * Facade providing typed methods for Application layer, internally using
 * query objects for self-contained SQL construction and response mapping.
 *
 * @template-pattern Infrastructure API Client Facade
 */
final readonly class OrderDashboardsClient implements OrderDashboardsClientInterface
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
    public function getProcessedOrderIdsByOrderDate(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array {
        /** @var list<Guid> */
        return $this->dashboardsClient->execute(new ProcessedOrderIdsQuery($from, $to));
    }
}
