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
        $pricesByStockItemId = [];

        foreach ($purchasePricesBySku as $sku => $price) {
            if (isset($stockItemIdsBySku[$sku])) {
                $pricesByStockItemId[$stockItemIdsBySku[$sku]] = $price;
            }
        }

        foreach ($pricesByStockItemId as $stockItemId => $price) {
            $this->eloquentGateway->query(
                static fn(): int => StockItemSupplierModel::query()
                    ->where('stock_item_id', $stockItemId)
                    ->where('supplier_name', $supplierName)
                    ->update(['purchase_price' => $price]),
            );
        }
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
