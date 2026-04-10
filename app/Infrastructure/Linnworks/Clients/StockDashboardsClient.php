<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Inventory\DTOs\StockLevelDeltaDTO;
use App\Application\Linnworks\DTOs\ArchivedStockItemDTO;
use App\Application\Linnworks\DTOs\ArchivedStockItemFlagsDTO;
use App\Application\Linnworks\DTOs\ModifiedStockItemDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\ArchivedStockItemsFullQuery;
use App\Infrastructure\Linnworks\Queries\ArchivedStockItemsQuery;
use App\Infrastructure\Linnworks\Queries\DeltaStockLevelQuery;
use App\Infrastructure\Linnworks\Queries\FullStockLevelQuery;
use App\Infrastructure\Linnworks\Queries\ModifiedStockItemQuery;
use App\Infrastructure\Linnworks\Queries\StockItemBySkuQuery;
use DateTimeImmutable;

/**
 * Stock-related queries via Linnworks Dashboards SQL API.
 *
 * Facade providing typed methods for Application layer, internally using
 * query objects for self-contained SQL construction and response mapping.
 *
 * @template-pattern Infrastructure API Client Facade
 */
final readonly class StockDashboardsClient implements StockDashboardsClientInterface
{
    public function __construct(
        private DashboardsClient $dashboardsClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws InvalidApiResponseException When query fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function findStockItemsBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        /** @var array<string, Guid> */
        return $this->dashboardsClient->execute(new StockItemBySkuQuery($skus));
    }

    /**
     * {@inheritDoc}
     *
     * @return list<ItemStockLevel>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getAllStockLevels(): array
    {
        /** @var list<ItemStockLevel> */
        return $this->dashboardsClient->execute(new FullStockLevelQuery());
    }

    /**
     * {@inheritDoc}
     *
     * @return list<StockLevelDeltaDTO>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getStockLevelsSince(DateTimeImmutable $since): array
    {
        /** @var list<StockLevelDeltaDTO> */
        return $this->dashboardsClient->execute(new DeltaStockLevelQuery($since));
    }

    /**
     * {@inheritDoc}
     *
     * @return list<ModifiedStockItemDTO>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getModifiedStockItemIdsSince(DateTimeImmutable $since): array
    {
        /** @var list<ModifiedStockItemDTO> */
        return $this->dashboardsClient->execute(new ModifiedStockItemQuery($since));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getArchivedStockItemIds(): ArchivedStockItemFlagsDTO
    {
        /** @var ArchivedStockItemFlagsDTO */
        return $this->dashboardsClient->execute(new ArchivedStockItemsQuery());
    }

    /**
     * {@inheritDoc}
     *
     * @return list<ArchivedStockItemDTO>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getArchivedStockItemsFull(): array
    {
        /** @var list<ArchivedStockItemDTO> */
        return $this->dashboardsClient->execute(new ArchivedStockItemsFullQuery());
    }
}
