<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\StockItemSupplierRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
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
 * Uses Eloquent queries instead of raw SQL for maintainability.
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

        $this->eloquentGateway->query(
            static fn(): int => self::applyPriceUpdates($supplierName, $purchasePricesBySku, $stockItemIdsBySku),
        );
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

        $suppliers = $this->fetchSuppliersForStockItems(\array_values($stockItemIds));

        return self::groupSuppliersBySku(\array_flip($stockItemIds), $suppliers);
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

    /**
     * Update supplier rows by resolved stock item ID.
     *
     * Called from within a gateway-wrapped closure — DB exceptions
     * are translated by the enclosing gateway call.
     *
     * @param array<string, float> $purchasePricesBySku
     * @param array<string, string> $stockItemIdsBySku
     */
    private static function applyPriceUpdates(string $supplierName, array $purchasePricesBySku, array $stockItemIdsBySku): int
    {
        $updated = 0;

        foreach ($purchasePricesBySku as $sku => $price) {
            $stockItemId = $stockItemIdsBySku[$sku] ?? null;

            if ($stockItemId === null) {
                continue;
            }

            $updated += StockItemSupplierModel::query()
                ->where('stock_item_id', $stockItemId)
                ->where('supplier_name', $supplierName)
                ->update(['purchase_price' => $price]);
        }

        return $updated;
    }

    /**
     * @param array<string, string> $skuByStockItemId
     * @param Collection<int, StockItemSupplierModel> $suppliers
     *
     * @return array<string, list<ProductSupplier>>
     */
    private static function groupSuppliersBySku(array $skuByStockItemId, Collection $suppliers): array
    {
        $result = [];

        foreach ($suppliers as $model) {
            $sku = $skuByStockItemId[$model->stock_item_id] ?? null;

            if ($sku !== null) {
                $result[$sku][] = $model->toProductSupplier();
            }
        }

        return $result;
    }
}
