<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\UnsupportedFieldException;
use App\Domain\Inventory\Enums\InventoryUpdatableField;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Mappers\StockItemExtendedPropertyMapper;
use App\Infrastructure\Linnworks\Mappers\StockItemModelMapper;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use Override;

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
    #[Override]
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

            $this->replaceExtendedProperties($entity);
            $this->replaceSuppliers($entity);
        }, attempts: 3);
    }

    /**
     * Delete existing extended properties and re-insert fresh rows from the domain object.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function replaceExtendedProperties(StockItemFull $entity): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: StockItemExtendedPropertyModel::class,
            column: 'stock_item_id',
            value: $entity->stockItemId,
        );

        if (!$entity->hasExtendedProperties()) {
            return;
        }

        $rows = \array_map(
            static fn(StockItemExtendedProperty $ep): array => [
                'stock_item_id' => $entity->stockItemId,
                ...StockItemExtendedPropertyMapper::toModelAttributes($ep),
            ],
            $entity->extendedProperties,
        );

        $this->eloquentGateway->insertMany(
            modelClass: StockItemExtendedPropertyModel::class,
            rows: $rows,
        );
    }

    /**
     * Delete existing suppliers and re-insert fresh rows from the domain object.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function replaceSuppliers(StockItemFull $entity): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: StockItemSupplierModel::class,
            column: 'stock_item_id',
            value: $entity->stockItemId,
        );

        if (!$entity->hasSuppliers()) {
            return;
        }

        $rows = \array_map(
            static fn(StockItemSupplier $supplier): array => [
                'stock_item_id' => $entity->stockItemId,
                ...StockItemSupplierModel::attributesFromDomain($supplier),
            ],
            $entity->suppliers,
        );

        $this->eloquentGateway->insertMany(
            modelClass: StockItemSupplierModel::class,
            rows: $rows,
        );
    }

    /**
     * {@inheritDoc}
     *
     * Bulk-updates is_archived and is_logically_deleted in a single transaction.
     * Two-pass per flag: set true for flagged IDs, set false for any rows previously
     * set but no longer flagged (avoids blanket reset, only touches changed rows).
     *
     * @param list<Guid> $archivedIds
     * @param list<Guid> $deletedIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function syncArchivedFlags(array $archivedIds, array $deletedIds): void
    {
        $this->eloquentGateway->transact(static function () use ($archivedIds, $deletedIds): void {
            self::applyFlagSync('is_archived', $archivedIds);
            self::applyFlagSync('is_logically_deleted', $deletedIds);
        });
    }

    /**
     * Two-pass bulk update for a single boolean flag column.
     *
     * Sets the flag to true for flagged IDs, then resets it to false for any
     * rows that previously had the flag but are no longer in the flagged set.
     *
     * @param list<Guid> $flaggedIds
     */
    private static function applyFlagSync(string $column, array $flaggedIds): void
    {
        $values = \array_map(static fn(Guid $g): string => $g->value, $flaggedIds);

        if ($values !== []) {
            StockItemModel::query()->whereIn('stock_item_id', $values)->update([$column => true]);
        }
        StockItemModel::query()
            ->whereNotIn('stock_item_id', $values)
            ->where($column, true)
            ->update([$column => false]);
    }

    /**
     * {@inheritDoc}
     *
     * Bypasses the {@see save()} override entirely — does NOT delete or
     * re-insert extended-property or supplier child rows. This preserves
     * historical child data for items transitioning from active to
     * archived state, which the regular save path would destroy.
     *
     * Every row is marked `is_archived = true` and `is_logically_deleted = true`.
     * Linnworks always co-sets both flags on archived items (verified against
     * the `StockItem` table three-bucket breakdown), so hardcoding here keeps
     * the two columns in sync without a per-row flag in the input.
     *
     * Uses {@see EloquentGateway::batchUpsertMany()}
     * at the default batch size of 500 — ~8 batches for a typical ~3.6k
     * archived catalogue.
     *
     * @param list<StockItemFull> $items
     */
    public function upsertArchivedStockItems(array $items): SaveManyResult
    {
        if ($items === []) {
            return new SaveManyResult(succeeded: 0, failed: 0, failedReferences: []);
        }

        $rows = \array_map(
            static fn(StockItemFull $item): array => [
                ...StockItemModelMapper::toModelAttributes($item),
                'is_archived' => true,
                'is_logically_deleted' => true,
            ],
            $items,
        );

        return $this->eloquentGateway->batchUpsertMany(
            modelClass: StockItemModel::class,
            rows: $rows,
            uniqueBy: ['stock_item_id'],
            identifierColumn: 'stock_item_id',
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Guid> $compositeIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function syncCompositeFlags(array $compositeIds): void
    {
        $this->eloquentGateway->transact(static function () use ($compositeIds): void {
            self::applyFlagSync('is_composite', $compositeIds);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, Guid> SKU → stock_item_id (only SKUs found locally)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function resolveStockItemIdsBySkus(Sku ...$skus): array
    {
        if ($skus === []) {
            return [];
        }

        $skuStrings = \array_map(static fn(Sku $s): string => $s->value, $skus);

        /** @var array<string, string> $raw */
        $raw = $this->eloquentGateway->query(
            static fn(): array => StockItemModel::query()
                ->whereIn('item_number', $skuStrings)
                ->pluck('stock_item_id', 'item_number')
                ->all(),
        );

        return \array_map(static fn(string $id): Guid => new Guid($id), $raw);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, list<InventoryFieldUpdate>> $updatesBySku
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function bulkUpdateInventoryFieldsBySkus(array $updatesBySku): int
    {
        if ($updatesBySku === []) {
            return 0;
        }

        // Group updates by column: column => [sku => primitive value]
        $valuesByColumn = [];
        foreach ($updatesBySku as $sku => $updates) {
            foreach ($updates as $update) {
                [$column, $value] = self::fieldMapping($update);
                $valuesByColumn[$column][$sku] = $value;
            }
        }

        /** @var int $totalAffected */
        $totalAffected = $this->eloquentGateway->transact(static function () use ($valuesByColumn): int {
            $running = 0;
            foreach ($valuesByColumn as $column => $valuesBySku) {
                $running += self::executeBulkColumnUpdate($column, $valuesBySku);
            }
            return $running;
        });

        return $totalAffected;
    }

    /**
     * Run a single PostgreSQL VALUES-join UPDATE for one inventory column.
     *
     * @param array<string, bool|int> $valuesBySku  SKU → primitive value for this column
     */
    private static function executeBulkColumnUpdate(string $column, array $valuesBySku): int
    {
        if ($valuesBySku === []) {
            return 0;
        }

        $valueCast = self::columnSqlCast($column);
        $bindings = [];
        foreach ($valuesBySku as $sku => $value) {
            $bindings[] = $sku;
            $bindings[] = $value;
        }

        $valuesPairs = \implode(', ', \array_fill(0, \count($valuesBySku), "(?::text, ?::{$valueCast})"));

        return StockItemModel::query()->getConnection()->update(
            "UPDATE linnworks.stock_items AS t SET {$column} = c.value FROM (VALUES {$valuesPairs}) AS c(item_number, value) WHERE t.item_number = c.item_number",
            $bindings,
        );
    }

    /**
     * PostgreSQL cast keyword for the value column of a VALUES-join UPDATE.
     */
    private static function columnSqlCast(string $column): string
    {
        return match ($column) {
            'jit' => 'boolean',
            'minimum_level' => 'integer',
            default => throw new UnsupportedFieldException(
                fieldName: $column,
                entityType: 'StockItem',
            ),
        };
    }

    /**
     * @return array{0: string, 1: bool|int}
     *
     * @throws UnsupportedFieldException When the field has no local column mapping
     */
    private static function fieldMapping(InventoryFieldUpdate $update): array
    {
        return match ($update->field) {
            InventoryUpdatableField::JIT => ['jit', $update->value === 'true'],
            InventoryUpdatableField::MinimumLevel => ['minimum_level', (int) $update->value],
            InventoryUpdatableField::Category,
            InventoryUpdatableField::RetailPrice,
            InventoryUpdatableField::PurchasePrice,
            InventoryUpdatableField::BinRack,
            InventoryUpdatableField::Barcode,
            InventoryUpdatableField::Weight,
            InventoryUpdatableField::Title => throw new UnsupportedFieldException(
                fieldName: $update->field->name,
                entityType: 'StockItem',
            ),
        };
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
