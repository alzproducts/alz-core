<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for StockItemBySkuQuery results.
 *
 * @internal Implementation detail of StockItemBySkuQuery
 */
final class StockItemBySkuRow extends Data
{
    public function __construct(
        #[MapInputName('pkStockItemID')]
        public readonly string $stockItemId,
        #[MapInputName('ItemNumber')]
        public readonly string $sku,
    ) {}
}

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
     * @param list<string> $skus SKUs to look up (must not be empty)
     *
     * @throws InvalidArgumentException When SKU list is empty
     */
    public function __construct(
        private array $skus,
    ) {
        if ($this->skus === []) {
            throw new InvalidArgumentException('SKU list cannot be empty');
        }
    }

    protected function buildQueryBody(): string
    {
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
            $parsed = StockItemBySkuRow::from($row);
            $results[$parsed->sku] = new Guid($parsed->stockItemId);
        }

        return $results;
    }
}
