<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use InvalidArgumentException;

/**
 * Query stock items by SKU, including soft-deleted items.
 *
 * This query solves a critical blocker: Linnworks' AddInventoryItem silently
 * fails (returns 204) when a SKU matches a soft-deleted item, and
 * GetStockItemIdsBySKU doesn't return soft-deleted items.
 *
 * By querying the StockItem table directly, we can detect SKU collisions
 * including soft-deleted items before attempting to create new inventory.
 *
 * @extends AbstractLinnworksQuery<array<string, Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class StockItemBySkuQuery extends AbstractLinnworksQuery
{
    /**
     * @param list<string> $skus SKUs to look up
     */
    public function __construct(
        private array $skus,
    ) {}

    /**
     * @throws InvalidArgumentException When SKU list is empty
     */
    protected function buildQueryBody(): string
    {
        if ($this->skus === []) {
            throw new InvalidArgumentException('SKU list cannot be empty');
        }

        $inClause = SqlQueryBuilder::buildInClause($this->skus);

        return "SELECT pkStockItemID, ItemNumber FROM StockItem WHERE ItemNumber IN {$inClause}";
    }

    /**
     * Map query results to SKU => Guid mapping.
     *
     * @return array<string, Guid> SKU => stockItemId (only SKUs that exist)
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        $results = [];

        foreach ($response->results as $row) {
            $parsed = StockItemSkuRow::from($row);
            $results[$parsed->sku] = new Guid($parsed->stockItemId);
        }

        return $results;
    }
}
