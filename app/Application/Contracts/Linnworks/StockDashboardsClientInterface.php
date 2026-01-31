<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\ValueObjects\Guid;

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
}
