<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\LookupTables;

use App\Application\Contracts\LookupTableProviderInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;

/**
 * Provides product enrichment lookup table data from Linnworks and ShopWired.
 *
 * Maps SKUs to product context for Mixpanel analytics:
 * - group_identifier: ShopWired product ID (always parent, even for variants)
 * - title: Product title from ShopWired or Linnworks
 * - default_category: Linnworks category name
 * - default_supplier: Linnworks default supplier name
 *
 * Uses UNION to include ALL SKUs from either system:
 * 1. ShopWired SKUs (products + variations) with optional Linnworks enrichment
 * 2. Linnworks-only SKUs (discontinued products with order history)
 *
 * This ensures Mixpanel events for any product—current or discontinued—can
 * be enriched with whatever data is available.
 */
final readonly class ProductLookupTableProvider implements LookupTableProviderInterface
{
    public function __construct(
        private DatabaseGateway $database,
    ) {}

    public function getTableKey(): string
    {
        return 'product_enrichment';
    }

    public function getSourceName(): string
    {
        return 'Linnworks/ShopWired';
    }

    /**
     * @return list<string>
     */
    public function getHeaders(): array
    {
        return ['sku', 'group_identifier', 'title', 'default_category', 'default_supplier'];
    }

    /**
     * Fetch all SKUs with enrichment data.
     *
     * Uses UNION to combine:
     * 1. ShopWired SKUs (products + variations) LEFT JOIN Linnworks
     * 2. Linnworks-only SKUs (not in ShopWired)
     *
     * PostgreSQL DISTINCT ON ensures one row per SKU with deterministic
     * supplier selection (alphabetically first) when multiple exist.
     *
     * @return list<list<string>>
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When query fails permanently
     * @throws DuplicateRecordException When unique constraint violated (defensive - shouldn't occur in reads)
     */
    public function fetchRows(): array
    {
        /** @var list<object{sku: string, group_identifier: string, title: string, default_category: string|null, default_supplier: string|null}> $results */
        $results = $this->database->query(
            fn(): array => $this->database->connection()->select($this->buildQuery()),
        );

        return \array_map(
            static fn(object $row): array => [
                $row->sku,
                $row->group_identifier,
                $row->title,
                $row->default_category ?? '',
                $row->default_supplier ?? '',
            ],
            $results,
        );
    }

    /**
     * Build SQL query for product enrichment data using UNION.
     *
     * Combines three sources:
     * 1. ShopWired products with master SKU → LEFT JOIN Linnworks
     * 2. ShopWired variations → LEFT JOIN Linnworks
     * 3. Linnworks-only SKUs (not in ShopWired) → for discontinued products
     *
     * DISTINCT ON ensures exactly one row per SKU, with alphabetical supplier
     * selection for determinism when multiple default suppliers exist.
     *
     * group_identifier logic (with SKU as fallback):
     * - ShopWired products: external_id (the product itself)
     * - ShopWired variations: product_external_id (parent product)
     * - Linnworks-only or null: SKU itself (no grouping available)
     */
    private function buildQuery(): string
    {
        return <<<'SQL'
            SELECT DISTINCT ON (sku)
                sku,
                COALESCE(group_identifier, sku) AS group_identifier,
                title,
                default_category,
                default_supplier
            FROM (
                -- ShopWired products with master SKU
                SELECT
                    p.sku AS sku,
                    p.external_id::text AS group_identifier,
                    p.title AS title,
                    si.category_name AS default_category,
                    s.supplier_name AS default_supplier
                FROM shopwired.products p
                LEFT JOIN linnworks.stock_items si ON si.item_number = p.sku
                LEFT JOIN linnworks.stock_item_suppliers s
                    ON s.stock_item_id = si.stock_item_id AND s.is_default = true
                WHERE p.sku IS NOT NULL AND p.sku != ''

                UNION ALL

                -- ShopWired variations
                SELECT
                    pv.sku AS sku,
                    pv.product_external_id::text AS group_identifier,
                    p.title AS title,
                    si.category_name AS default_category,
                    s.supplier_name AS default_supplier
                FROM shopwired.product_variations pv
                JOIN shopwired.products p ON p.external_id = pv.product_external_id
                LEFT JOIN linnworks.stock_items si ON si.item_number = pv.sku
                LEFT JOIN linnworks.stock_item_suppliers s
                    ON s.stock_item_id = si.stock_item_id AND s.is_default = true
                WHERE pv.sku IS NOT NULL AND pv.sku != ''

                UNION ALL

                -- Linnworks-only SKUs (discontinued products not in ShopWired)
                SELECT
                    si.item_number AS sku,
                    si.item_number AS group_identifier,
                    si.item_title AS title,
                    si.category_name AS default_category,
                    s.supplier_name AS default_supplier
                FROM linnworks.stock_items si
                LEFT JOIN linnworks.stock_item_suppliers s
                    ON s.stock_item_id = si.stock_item_id AND s.is_default = true
                WHERE si.item_number != ''
                AND NOT EXISTS (
                    SELECT 1 FROM shopwired.products p WHERE p.sku = si.item_number
                )
                AND NOT EXISTS (
                    SELECT 1 FROM shopwired.product_variations pv WHERE pv.sku = si.item_number
                )
            ) combined
            ORDER BY sku, default_supplier NULLS LAST
            SQL;
    }
}
