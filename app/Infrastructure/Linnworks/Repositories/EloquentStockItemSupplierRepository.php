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

        $this->eloquentGateway->query(
            static fn(): int => self::updatePricesBySkuAndSupplier($supplierName, $purchasePricesBySku),
        );
    }

    /**
     * Resolve SKUs to stock item IDs, then update matching supplier rows.
     *
     * @param array<string, float> $purchasePricesBySku
     *
     * @return int Number of rows updated
     */
    private static function updatePricesBySkuAndSupplier(string $supplierName, array $purchasePricesBySku): int
    {
        $stockItemIdsBySku = self::resolveStockItemIds(\array_keys($purchasePricesBySku));
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
     * Resolve SKUs to stock_item_id GUIDs.
     *
     * @param list<string> $skus
     *
     * @return array<string, string> SKU → stock_item_id
     */
    private static function resolveStockItemIds(array $skus): array
    {
        /** @var array<string, string> */
        return StockItemModel::query()
            ->whereIn('item_number', $skus)
            ->pluck('stock_item_id', 'item_number')
            ->all();
    }
}
