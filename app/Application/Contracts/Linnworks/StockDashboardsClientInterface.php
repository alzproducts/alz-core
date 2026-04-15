<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Inventory\DTOs\StockLevelDeltaDTO;
use App\Application\Linnworks\DTOs\ArchivedStockItemFlagsDTO;
use App\Application\Linnworks\DTOs\ModifiedStockItemDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Domain\Inventory\ValueObjects\StockItemFull;
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

    /**
     * Fetch all archived and logically-deleted stock item IDs.
     *
     * Uses ExecuteCustomScriptQuery because these flags are not exposed
     * by the GetStockItemsFull REST API. Returns only flagged items for
     * targeted bulk updates — avoids fetching the full catalogue.
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getArchivedStockItemIds(): ArchivedStockItemFlagsDTO;

    /**
     * Fetch full field data for every archived stock item.
     *
     * Uses ExecuteCustomScriptQuery because all three Inventory REST endpoints
     * (`GetStockItemsFull`, `GetInventoryItemById`, `GetStockItemsFullByIds`)
     * silently filter archived items out of their responses. Rows with an
     * empty `ItemNumber` (SKU), and rows whose `ItemNumber` collides with
     * any other row in `StockItem`, are excluded at the SQL layer.
     *
     * Stock levels on the returned items are zero-filled (archived items
     * have no live stock). Extended properties and supplier rows are NOT
     * populated — the repository preserves any existing child records on
     * items transitioning to archived.
     *
     * @return list<StockItemFull>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getArchivedStockItemsFull(): array;

    /**
     * Fetch stock item IDs that are composite parents.
     *
     * Uses ExecuteCustomScriptQuery because `GetStockItemsFull` (bulk sync)
     * does not return the `IsCompositeParent` field. Returns only active
     * (non-archived) items where `bContainsComposites` is true.
     *
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getCompositeStockItemIds(): array;
}
