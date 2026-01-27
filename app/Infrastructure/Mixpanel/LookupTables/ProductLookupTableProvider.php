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
 * - default_category: Linnworks category name
 * - default_supplier: Linnworks default supplier name
 *
 * Only includes SKUs that have matching ShopWired products or variations
 * (orphan Linnworks SKUs are excluded).
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
        return ['sku', 'group_identifier', 'default_category', 'default_supplier'];
    }

    /**
     * Fetch all SKUs with enrichment data.
     *
     * Uses PostgreSQL DISTINCT ON for deterministic supplier selection when
     * multiple suppliers exist. Alphabetically first supplier name is chosen.
     *
     * @return list<list<string>>
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When query fails permanently
     * @throws DuplicateRecordException When unique constraint violated (defensive - shouldn't occur in reads)
     */
    public function fetchRows(): array
    {
        /** @var list<object{sku: string, group_identifier: string, default_category: string, default_supplier: string|null}> $results */
        $results = $this->database->query(
            fn(): array => $this->database->connection()->select($this->buildQuery()),
        );

        return \array_map(
            static fn(object $row): array => [
                $row->sku,
                $row->group_identifier,
                $row->default_category,
                $row->default_supplier ?? '',
            ],
            $results,
        );
    }

    /**
     * Build SQL query for product enrichment data.
     *
     * DISTINCT ON ensures exactly one row per SKU, with alphabetical supplier
     * selection for determinism when multiple default suppliers exist.
     *
     * The COALESCE on group_identifier handles both products and variations:
     * - Products: use external_id directly
     * - Variations: use product_external_id (parent product ID)
     */
    private function buildQuery(): string
    {
        return <<<'SQL'
            SELECT DISTINCT ON (si.item_number)
                si.item_number AS sku,
                COALESCE(p.external_id, pv.product_external_id)::text AS group_identifier,
                si.category_name AS default_category,
                s.supplier_name AS default_supplier
            FROM linnworks.stock_items si
            LEFT JOIN shopwired.products p ON p.sku = si.item_number
            LEFT JOIN shopwired.product_variations pv ON pv.sku = si.item_number
            LEFT JOIN linnworks.stock_item_suppliers s
                ON s.stock_item_id = si.stock_item_id AND s.is_default = true
            WHERE p.sku IS NOT NULL OR pv.sku IS NOT NULL
            ORDER BY si.item_number, s.supplier_name NULLS LAST
            SQL;
    }
}
