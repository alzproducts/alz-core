<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Infrastructure\Linnworks\Mappers\StockItemExtendedPropertyMapper;
use App\Infrastructure\Linnworks\Mappers\StockItemModelMapper;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

/**
 * Eloquent implementation of Linnworks stock item repository.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * Each save is wrapped in a transaction for atomicity.
 *
 * @extends AbstractEloquentRepository<StockItem>
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
     *
     * @param StockItem $entity
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
        }, attempts: 3);
    }

    protected function getModelClass(): string
    {
        return StockItemModel::class;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var StockItem $entity */
        return $entity->stockItemId;
    }
}
