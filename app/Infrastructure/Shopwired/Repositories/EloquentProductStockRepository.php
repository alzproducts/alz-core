<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Models\ProductModel;

/**
 * Reads and bulk-updates stock levels in the local ShopWired database.
 *
 * Bridges both shopwired.products and shopwired.product_variations via
 * UNION queries. Product vs. variation routing on writes is resolved
 * internally — callers work with ItemStockLevel only.
 *
 * Update strategy (max 3 queries regardless of batch size):
 * 1. UNION type-detection query (SKU → product/variation)
 * 2. Bulk UPDATE shopwired.products  (VALUES join)
 * 3. Bulk UPDATE shopwired.product_variations (VALUES join)
 */
final readonly class EloquentProductStockRepository implements ProductStockRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<ItemStockLevel>
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function getAllStockLevels(): array
    {
        return $this->gateway->query(static function (): array {
            $sql = <<<'SQL'
                SELECT sku, GREATEST(COALESCE(stock, 0), 0) AS stock
                FROM shopwired.products
                WHERE sku IS NOT NULL
                UNION
                SELECT sku, GREATEST(COALESCE(stock, 0), 0) AS stock
                FROM shopwired.product_variations
                WHERE sku IS NOT NULL
                SQL;

            /** @var list<object{sku: string, stock: int}> $rows */
            $rows = ProductModel::query()->getConnection()->select($sql);

            return self::mapToItemStockLevels($rows);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Sku> $skus
     *
     * @return list<ItemStockLevel>
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function getStockLevelsBySkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $skuValues = \array_map(static fn(Sku $sku): string => $sku->value, $skus);
        $placeholders = \implode(',', \array_fill(0, \count($skuValues), '?'));

        return $this->gateway->query(static function () use ($skuValues, $placeholders): array {
            $sql = <<<SQL
                SELECT sku, GREATEST(COALESCE(stock, 0), 0) AS stock
                FROM shopwired.products
                WHERE sku IN ({$placeholders})
                UNION
                SELECT sku, GREATEST(COALESCE(stock, 0), 0) AS stock
                FROM shopwired.product_variations
                WHERE sku IN ({$placeholders})
                SQL;

            /** @var list<object{sku: string, stock: int}> $rows */
            $rows = ProductModel::query()->getConnection()->select(
                $sql,
                \array_merge($skuValues, $skuValues),
            );

            return self::mapToItemStockLevels($rows);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param list<ItemStockLevel> $items
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function updateStockLevels(array $items): void
    {
        if ($items === []) {
            return;
        }

        $skuValues = \array_map(static fn(ItemStockLevel $item): string => $item->sku->value, $items);
        $typeMap = $this->buildTypeMap($skuValues);

        $productItems = \array_values(\array_filter(
            $items,
            static fn(ItemStockLevel $i): bool => ($typeMap[$i->sku->value] ?? null) === 'product',
        ));

        $variationItems = \array_values(\array_filter(
            $items,
            static fn(ItemStockLevel $i): bool => ($typeMap[$i->sku->value] ?? null) === 'variation',
        ));

        if ($productItems === [] && $variationItems === []) {
            return;
        }

        $this->gateway->transact(static function () use ($productItems, $variationItems): void {
            if ($productItems !== []) {
                self::bulkUpdateStock('shopwired.products', $productItems);
            }

            if ($variationItems !== []) {
                self::bulkUpdateStock('shopwired.product_variations', $variationItems);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Map raw stock DB rows to ItemStockLevel domain objects.
     *
     * @param list<object{sku: string, stock: int}> $rows
     *
     * @return list<ItemStockLevel>
     */
    private static function mapToItemStockLevels(array $rows): array
    {
        return \array_map(
            static fn(object $row): ItemStockLevel => new ItemStockLevel(
                sku: Sku::fromTrusted($row->sku),
                quantity: $row->stock,
            ),
            $rows,
        );
    }

    /**
     * Determine whether each SKU belongs to products or product_variations.
     *
     * Uses a single UNION query to classify all SKUs in one round-trip.
     *
     * @param list<string> $skuValues
     *
     * @return array<string, 'product'|'variation'>
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function buildTypeMap(array $skuValues): array
    {
        $placeholders = \implode(',', \array_fill(0, \count($skuValues), '?'));

        return $this->gateway->query(static function () use ($skuValues, $placeholders): array {
            $sql = <<<SQL
                SELECT sku, 'product' AS type
                FROM shopwired.products
                WHERE sku IN ({$placeholders})
                UNION
                SELECT sku, 'variation' AS type
                FROM shopwired.product_variations
                WHERE sku IN ({$placeholders})
                SQL;

            /** @var list<object{sku: string, type: string}> $rows */
            $rows = ProductModel::query()->getConnection()->select(
                $sql,
                \array_merge($skuValues, $skuValues),
            );

            /** @var array<string, 'product'|'variation'> $map */
            $map = [];

            foreach ($rows as $row) {
                /** @var 'product'|'variation' $type */
                $type = $row->type;
                $map[$row->sku] = $type;
            }

            return $map;
        });
    }

    /**
     * Bulk-update the stock column via a single VALUES-join UPDATE.
     *
     * @param list<ItemStockLevel> $items
     */
    private static function bulkUpdateStock(string $table, array $items): void
    {
        $valuesPairs = \implode(
            ', ',
            \array_fill(0, \count($items), '(?::text, ?::int)'),
        );

        $bindings = [];

        foreach ($items as $item) {
            $bindings[] = $item->sku->value;
            $bindings[] = $item->quantity;
        }

        ProductModel::query()->getConnection()->statement(
            "UPDATE {$table} AS t SET stock = c.stock FROM (VALUES {$valuesPairs}) AS c(sku, stock) WHERE t.sku = c.sku",
            $bindings,
        );
    }
}
