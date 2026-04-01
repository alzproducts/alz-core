<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Factories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Linnworks\Models\StockItemModel;

/**
 * Lazy-loaded factory for product supplier data.
 *
 * Loads all supplier data in a single query on first access, then serves
 * O(1) lookups by SKU. Eliminates N+1 queries on list endpoints.
 *
 * **Lifecycle**: Register with `scoped()` — fresh instance per request/job (Octane isolation).
 */
final class ProductSupplierFactory
{
    /** @var array<string, list<ProductSupplier>>|null SKU → suppliers map, null = not loaded */
    private ?array $suppliersBySku = null;

    public function __construct(
        private readonly DatabaseGatewayInterface $gateway,
    ) {}

    /**
     * Get all suppliers for a product by SKU.
     *
     * @return list<ProductSupplier>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getByProductSku(string $sku): array
    {
        return $this->supplierMap()[$sku] ?? [];
    }

    /**
     * Lazy-load all supplier data keyed by SKU.
     *
     * @return array<string, list<ProductSupplier>>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function supplierMap(): array
    {
        if ($this->suppliersBySku === null) {
            $this->suppliersBySku = $this->loadAll();
        }

        return $this->suppliersBySku;
    }

    /**
     * Load all stock item suppliers joined with their SKUs.
     *
     * Single query — groups results into SKU-keyed map after fetch.
     *
     * @return array<string, list<ProductSupplier>>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function loadAll(): array
    {
        return $this->gateway->query(static function (): array {
            /** @var list<object{sku: string, supplier_name: string, purchase_price: string|float|null, is_default: bool}> $rows */
            $rows = (new StockItemModel())->getConnection()->select(self::suppliersSql());

            return self::groupBySkuRows($rows);
        });
    }

    private static function suppliersSql(): string
    {
        return <<<'SQL'
            SELECT
                si.item_number AS sku,
                s.supplier_name,
                s.purchase_price,
                s.is_default
            FROM linnworks.stock_item_suppliers s
            JOIN linnworks.stock_items si ON si.stock_item_id = s.stock_item_id
            WHERE si.item_number IS NOT NULL
                AND si.item_number != ''
            ORDER BY si.item_number, s.is_default DESC
            SQL;
    }

    /**
     * @param list<object{sku: string, supplier_name: string, purchase_price: string|float|null, is_default: bool}> $rows
     *
     * @return array<string, list<ProductSupplier>>
     */
    private static function groupBySkuRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[$row->sku][] = new ProductSupplier(
                supplierName: $row->supplier_name,
                purchasePrice: $row->purchase_price !== null ? (float) $row->purchase_price : null,
                isDefault: $row->is_default,
            );
        }

        return $result;
    }
}
