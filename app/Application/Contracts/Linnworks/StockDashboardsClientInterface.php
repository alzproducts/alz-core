<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Inventory\DTOs\StockLevelDeltaDTO;
use App\Application\Linnworks\DTOs\ModifiedStockItemDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Contract for Linnworks stock-related SQL queries.
 *
 * Uses the Dashboards/ExecuteCustomScriptQuery endpoint for direct
 * database access, enabling queries that the REST API doesn't support
 * (e.g., finding soft-deleted items).
 *
 * @template-pattern Application Contract Interface
 */
interface StockDashboardsClientInterface
{
    /**
     * Find stock items by SKU, including soft-deleted items.
     *
     * Unlike GetStockItemIdsBySKU, this query returns ALL matching items
     * regardless of deletion status. This is critical for detecting SKU
     * collisions before AddInventoryItem (which silently fails on soft-deleted SKUs).
     *
     * @param list<string> $skus SKUs to look up
     *
     * @return array<string, Guid> SKU => stockItemId (only SKUs that exist)
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function findStockItemsBySku(array $skus): array;

    /**
     * Fetch all stock levels from Linnworks.
     *
     * @return list<ItemStockLevel>
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function getAllStockLevels(): array;

    /**
     * Fetch stock levels changed since the given datetime.
     *
     * Results are ordered by LastUpdateDate ASC — callers rely on this
     * to advance cursors from the final element.
     *
     * @return list<StockLevelDeltaDTO>
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function getStockLevelsSince(DateTimeImmutable $since): array;

    /**
     * Fetch stock item IDs modified since the given datetime.
     *
     * Results are ordered by ModifiedDate ASC — callers rely on this
     * to advance cursors from the final element. Returns up to 500 rows;
     * if exactly 500 are returned, the caller should assume overflow.
     *
     * @return list<ModifiedStockItemDTO>
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function getModifiedStockItemIdsSince(DateTimeImmutable $since): array;
}
