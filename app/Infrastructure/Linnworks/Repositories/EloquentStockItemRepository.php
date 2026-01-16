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

/**
 * Eloquent implementation of Linnworks stock item repository.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * Each save is wrapped in a transaction for atomicity.
 *
 * @extends AbstractLinnworksEloquentRepository<StockItem>
 */
final class EloquentStockItemRepository extends AbstractLinnworksEloquentRepository implements StockItemRepositoryInterface
{
    private const string ENTITY_TYPE = 'StockItem';

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
        $this->gateway->transact(static function () use ($entity): void {
            // 1. Upsert stock item by stock_item_id
            StockItemModel::query()->updateOrCreate(
                ['stock_item_id' => $entity->stockItemId],
                StockItemModelMapper::toModelAttributes($entity),
            );

            // 2. Delete existing extended properties
            StockItemExtendedPropertyModel::query()
                ->where('stock_item_id', $entity->stockItemId)
                ->delete();

            // 3. Insert fresh extended properties
            if ($entity->hasExtendedProperties()) {
                $epRecords = \array_map(
                    static fn(StockItemExtendedProperty $ep): array => [
                        'stock_item_id' => $entity->stockItemId,
                        ...StockItemExtendedPropertyMapper::toModelAttributes($ep),
                    ],
                    $entity->extendedProperties,
                );

                StockItemExtendedPropertyModel::query()->insert($epRecords);
            }
        }, attempts: 3);
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var StockItem $entity */
        return $entity->stockItemId;
    }

    protected function getEntityTypeName(): string
    {
        return self::ENTITY_TYPE;
    }
}
