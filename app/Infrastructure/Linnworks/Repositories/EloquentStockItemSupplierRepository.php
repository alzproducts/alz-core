<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\StockItemSupplierRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation for targeted stock item supplier updates.
 *
 * Uses Eloquent for reads and raw SQL for bulk updates (PostgreSQL VALUES-join).
 * For full sync (delete/re-insert), see EloquentStockItemRepository::save().
 */
final readonly class EloquentStockItemSupplierRepository implements StockItemSupplierRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function bulkUpdatePurchasePrices(string $supplierName, array $purchasePricesBySku): void
    {
        if ($purchasePricesBySku === []) {
            return;
        }

        $stockItemIdsBySku = $this->resolveStockItemIds(\array_keys($purchasePricesBySku));
        [$valuesPairs, $bindings] = self::buildBulkUpdateBindings($stockItemIdsBySku, $purchasePricesBySku, $supplierName);

        if ($valuesPairs === '') {
            return;
        }

        $this->eloquentGateway->query(
            static fn(): int => StockItemSupplierModel::query()->getConnection()->update(
                "UPDATE linnworks.stock_item_suppliers AS t SET purchase_price = c.price FROM (VALUES {$valuesPairs}) AS c(stock_item_id, price) WHERE t.stock_item_id = c.stock_item_id AND t.supplier_name = ?",
                $bindings,
            ),
        );
    }

    /**
     * Build VALUES pairs and parameter bindings for the bulk price update.
     *
     * @param array<string, string>     $stockItemIdsBySku  SKU → stock_item_id
     * @param array<string, float> $purchasePricesBySku SKU → price
     *
     * @return array{string, list<string|float>} [valuesPairs, bindings]
     */
    private static function buildBulkUpdateBindings(array $stockItemIdsBySku, array $purchasePricesBySku, string $supplierName): array
    {
        $bindings = [];

        foreach ($purchasePricesBySku as $sku => $price) {
            if (isset($stockItemIdsBySku[$sku])) {
                $bindings[] = $stockItemIdsBySku[$sku];
                $bindings[] = $price;
            }
        }

        if ($bindings === []) {
            return ['', []];
        }

        $rowCount = \count($bindings) / 2;
        $valuesPairs = \implode(', ', \array_fill(0, (int) $rowCount, '(?::text, ?::numeric)'));
        $bindings[] = $supplierName;

        return [$valuesPairs, $bindings];
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getSuppliersBySkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $stockItemIds = $this->resolveStockItemIds($skus);

        if ($stockItemIds === []) {
            return [];
        }

        $skuByStockItemId = \array_flip($stockItemIds);
        $suppliers = $this->fetchSuppliersForStockItems(\array_values($stockItemIds));
        $result = [];

        foreach ($suppliers as $model) {
            $sku = $skuByStockItemId[$model->stock_item_id] ?? null;

            if ($sku !== null) {
                $result[$sku][] = $model->toProductSupplier();
            }
        }

        return $result;
    }

    /**
     * Resolve SKUs to stock_item_id GUIDs.
     *
     * @param list<string> $skus
     *
     * @return array<string, string> SKU → stock_item_id
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveStockItemIds(array $skus): array
    {
        /** @var array<string, string> */
        return $this->eloquentGateway->query(
            static fn(): array => StockItemModel::query()
                ->whereIn('item_number', $skus)
                ->pluck('stock_item_id', 'item_number')
                ->all(),
        );
    }

    /**
     * @param list<string> $stockItemIds
     *
     * @return Collection<int, StockItemSupplierModel>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function fetchSuppliersForStockItems(array $stockItemIds): Collection
    {
        /** @var Collection<int, StockItemSupplierModel> */
        return $this->eloquentGateway->query(
            static fn(): Collection => StockItemSupplierModel::query()
                ->whereIn('stock_item_id', $stockItemIds)
                ->orderByDesc('is_default')
                ->get(),
        );
    }
}
