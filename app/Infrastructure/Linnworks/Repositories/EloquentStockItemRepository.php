<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Infrastructure\Linnworks\Mappers\StockItemExtendedPropertyMapper;
use App\Infrastructure\Linnworks\Mappers\StockItemModelMapper;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

/**
 * Eloquent implementation of Linnworks stock item repository.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 * - Suppliers: delete/re-insert (catches removals in Linnworks)
 *
 * Each save is wrapped in a transaction for atomicity.
 *
 * @extends AbstractEloquentRepository<StockItemFull>
 */
final class EloquentStockItemRepository extends AbstractEloquentRepository implements StockItemRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * Strategy:
     * 1. Upsert stock item by stock_item_id
     * 2. Delete existing extended properties for this item
     * 3. Insert fresh extended properties from domain object
     * 4. Delete existing suppliers for this item
     * 5. Insert fresh suppliers from domain object
     *
     * @param StockItemFull $entity
     */
    public function save(object $entity): void
    {
        $this->eloquentGateway->transact(function () use ($entity): void {
            // 1. Upsert stock item by stock_item_id (single query vs SELECT+INSERT)
            $this->eloquentGateway->upsertOne(
                modelClass: StockItemModel::class,
                attributes: [
                    'stock_item_id' => $entity->stockItemId,
                    ...StockItemModelMapper::toModelAttributes($entity),
                ],
                uniqueBy: ['stock_item_id'],
            );

            // 2. Delete existing extended properties
            $this->eloquentGateway->deleteWhere(
                modelClass: StockItemExtendedPropertyModel::class,
                column: 'stock_item_id',
                value: $entity->stockItemId,
            );

            // 3. Insert fresh extended properties
            if ($entity->hasExtendedProperties()) {
                $epRecords = \array_map(
                    static fn(StockItemExtendedProperty $ep): array => [
                        'stock_item_id' => $entity->stockItemId,
                        ...StockItemExtendedPropertyMapper::toModelAttributes($ep),
                    ],
                    $entity->extendedProperties,
                );

                $this->eloquentGateway->insertMany(
                    modelClass: StockItemExtendedPropertyModel::class,
                    rows: $epRecords,
                );
            }

            // 4. Delete existing suppliers
            $this->eloquentGateway->deleteWhere(
                modelClass: StockItemSupplierModel::class,
                column: 'stock_item_id',
                value: $entity->stockItemId,
            );

            // 5. Insert fresh suppliers
            if ($entity->hasSuppliers()) {
                $supplierRecords = \array_map(
                    static fn(StockItemSupplier $supplier): array => [
                        'stock_item_id' => $entity->stockItemId,
                        ...StockItemSupplierModel::attributesFromDomain($supplier),
                    ],
                    $entity->suppliers,
                );

                $this->eloquentGateway->insertMany(
                    modelClass: StockItemSupplierModel::class,
                    rows: $supplierRecords,
                );
            }
        }, attempts: 3);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, float>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getCostPricesBySku(): array
    {
        return $this->eloquentGateway->query(static function (): array {
            $sql = <<<'SQL'
                SELECT si.item_number AS sku, s.purchase_price
                FROM linnworks.stock_items si
                JOIN linnworks.stock_item_suppliers s
                    ON s.stock_item_id = si.stock_item_id AND s.is_default = true
                WHERE si.item_number IS NOT NULL
                    AND si.item_number != ''
                    AND s.purchase_price IS NOT NULL
                SQL;

            /** @var list<object{sku: string, purchase_price: string|float}> $rows */
            $rows = StockItemModel::query()->getConnection()->select($sql);

            /** @var array<string, float> $result */
            $result = [];

            foreach ($rows as $row) {
                $result[$row->sku] = (float) $row->purchase_price;
            }

            return $result;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function updateSupplierPurchasePrice(Sku $sku, string $supplierName, float $purchasePrice): void
    {
        $this->eloquentGateway->query(static function () use ($sku, $supplierName, $purchasePrice): void {
            $sql = <<<'SQL'
                UPDATE linnworks.stock_item_suppliers s
                SET purchase_price = ?, updated_at = NOW()
                FROM linnworks.stock_items si
                WHERE s.stock_item_id = si.stock_item_id
                    AND si.item_number = ?
                    AND s.supplier_name = ?
                SQL;

            StockItemModel::query()->getConnection()->statement($sql, [
                $purchasePrice,
                $sku->value,
                $supplierName,
            ]);
        });
    }

    protected function getModelClass(): string
    {
        return StockItemModel::class;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var StockItemFull $entity */
        return $entity->stockItemId;
    }

    /**
     * {@inheritDoc}
     *
     * @param StockItemFull $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'stock_item_id' => $entity->stockItemId,
            ...StockItemModelMapper::toModelAttributes($entity),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['stock_item_id'];
    }
}
