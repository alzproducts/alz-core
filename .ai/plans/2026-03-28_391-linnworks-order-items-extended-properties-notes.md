# Plan: Linnworks Order Child Entities (Items, ExtendedProperties, Notes)

## Context

The current `SyncLinnworksOrdersUseCase` syncs order-level data only via bulk upsert. The Linnworks v2 GetOrders API already returns child entities (Items, ExtendedProperties, Notes) that we're ignoring. This change adds persistence for these children, requiring a shift from bulk batch upsert to per-order transactional sync (parent + children atomically).

**Key design decisions:**
- Transaction managed by repository's `save()` override (matching `EloquentStockItemRepository` pattern)
- Items & ExtendedProperties: **upsert by RowId + delete orphans** (stable unique IDs)
- Notes & BinRacks: **JSONB** columns (small collections, no independent queryability needed)
- CompositeSubItems: **flattened** into `order_items` table with nullable `parent_item_id`

---

## Implementation Phases

### Phase 1: Domain Value Objects

**1a. Create `LinnworksOrderItem`**
- File: `app/Domain/Linnworks/ValueObjects/LinnworksOrderItem.php`
- `final readonly class` with constructor-promoted properties
- Types: `Guid` for `rowId`, `stockItemId`, `categoryId`, `parentItemId`; `IntId` for `stockItemIntId`; `DateTimeImmutable` for `addedDate`
- JSONB-bound arrays: `additionalInfo` (`list<array{optionId: string, property: string, value: string}>`), `binRacks` (`list<array{location: string, binRack: string, batchId: ?int, orderItemBatchId: ?int, quantity: int, addedDate: ?string}>`)
- No `compositeSubItems` property — flattened at DTO level
- Fields from API spec:

| Property | Domain Type | Nullable |
|----------|------------|----------|
| rowId | Guid | no |
| parentItemId | ?Guid | yes |
| stockItemId | Guid | no |
| stockItemIntId | IntId | no |
| itemNumber | string | no |
| sku | string | no |
| itemSource | string | no |
| title | string | no |
| categoryId | Guid | no |
| categoryName | string | no |
| quantity | int | no |
| pricePerUnit | float | no |
| unitCost | float | no |
| despatchStockUnitCost | float | no |
| discount | float | no |
| taxRate | float | no |
| cost | float | no |
| costIncTax | float | no |
| salesTax | float | no |
| taxCostInclusive | bool | no |
| discountValue | float | no |
| weight | float | no |
| barcodeNumber | string | no |
| channelSku | string | no |
| channelTitle | string | no |
| batchNumberScanRequired | bool | no |
| serialNumberScanRequired | bool | no |
| isService | bool | no |
| isUnlinked | bool | no |
| addedDate | DateTimeImmutable | no |
| additionalInfo | array | default [] |
| binRacks | array | default [] |

**1b. Create `LinnworksOrderExtendedProperty`**
- File: `app/Domain/Linnworks/ValueObjects/LinnworksOrderExtendedProperty.php`
- `final readonly class`
- Fields: `rowId` (Guid), `name` (string), `value` (string), `type` (string), `createDate` (DateTimeImmutable), `lastUpdate` (DateTimeImmutable), `updatedBy` (string)

**1c. Create `LinnworksOrderNote`**
- File: `app/Domain/Linnworks/ValueObjects/LinnworksOrderNote.php`
- `final readonly class`
- Fields: `orderNoteId` (Guid), `noteDate` (DateTimeImmutable), `internal` (bool), `note` (string), `createdBy` (string), `noteTypeId` (?int)

**1d. Update `LinnworksOrder`**
- File: `app/Domain/Linnworks/ValueObjects/LinnworksOrder.php`
- Add three new constructor params (with defaults for backward compat):
  ```php
  /** @var list<LinnworksOrderItem> */
  public array $items = [],
  /** @var list<LinnworksOrderExtendedProperty> */
  public array $extendedProperties = [],
  /** @var list<LinnworksOrderNote> */
  public array $notes = [],
  ```

---

### Phase 2: Infrastructure — Response DTOs

**2a. Create `OrderItemResponse`**
- File: `app/Infrastructure/Linnworks/Responses/OrderItemResponse.php`
- Spatie LaravelData with `PascalCaseMapper`
- Has nullable `compositeSubItems` array of `self`
- `toDomain()` returns `list<LinnworksOrderItem>` — recursively flattens composite sub-items:
  ```php
  public function toDomain(): array
  {
      $item = new LinnworksOrderItem(
          rowId: new Guid($this->rowId),
          parentItemId: $this->parentItemId !== null ? new Guid($this->parentItemId) : null,
          // ... all other fields
          additionalInfo: array_map(fn($ai) => [...], $this->additionalInfo ?? []),
          binRacks: array_map(fn($br) => [...], $this->binRacks ?? []),
      );

      $items = [$item];
      foreach ($this->compositeSubItems ?? [] as $subItem) {
          array_push($items, ...$subItem->toDomain());
      }
      return $items;
  }
  ```

**2b. Create `OrderItemAdditionalInfoResponse`**
- File: `app/Infrastructure/Linnworks/Responses/OrderItemAdditionalInfoResponse.php`
- Fields: `optionId` (string), `property` (string), `value` (string)

**2c. Create `OrderItemBinRackResponse`**
- File: `app/Infrastructure/Linnworks/Responses/OrderItemBinRackResponse.php`
- Fields: `location` (string), `binRack` (string), `batchId` (?int), `orderItemBatchId` (?int), `quantity` (int), `addedDate` (?string)

**2d. Create `OrderExtendedPropertyResponse`**
- File: `app/Infrastructure/Linnworks/Responses/OrderExtendedPropertyResponse.php`
- Note Linnworks quirk: the field may be `ProperyName` (typo) — handle via explicit `#[MapInputName]` if needed
- `toDomain(): LinnworksOrderExtendedProperty`

**2e. Create `OrderNoteResponse`**
- File: `app/Infrastructure/Linnworks/Responses/OrderNoteResponse.php`
- `toDomain(): LinnworksOrderNote`

**2f. Update `OrderResponse`**
- File: `app/Infrastructure/Linnworks/Responses/OrderResponse.php`
- Add constructor params:
  ```php
  /** @var list<OrderItemResponse>|null */
  public readonly ?array $items = null,
  /** @var list<OrderExtendedPropertyResponse>|null */
  public readonly ?array $extendedProperties = null,
  /** @var list<OrderNoteResponse>|null */
  public readonly ?array $notes = null,
  ```
- Update `toDomain()` to map children (flatMap items to handle CompositeSubItems flattening):
  ```php
  items: array_merge(...array_map(fn(OrderItemResponse $r) => $r->toDomain(), $this->items ?? [])),
  extendedProperties: array_map(fn(OrderExtendedPropertyResponse $r) => $r->toDomain(), $this->extendedProperties ?? []),
  notes: array_map(fn(OrderNoteResponse $r) => $r->toDomain(), $this->notes ?? []),
  ```

---

### Phase 3: Database Migrations

**3a. Create `linnworks.order_items` table**
- File: `database/migrations/2026_03_28_000000_create_linnworks_order_items_table.php`
- Key columns:
  - `uuid('id')->primary()` (internal auto-gen)
  - `uuid('linnworks_order_id')` — FK to `linnworks.orders(linnworks_order_id)`, cascade delete
  - `uuid('row_id')->unique()` — Linnworks stable RowId, upsert key
  - `uuid('parent_item_id')->nullable()` — self-ref for CompositeSubItems
  - All item columns (see Phase 1a table)
  - `jsonb('additional_info')->nullable()`
  - `jsonb('bin_racks')->nullable()`
  - `timestampsTz()`
- Indexes: `linnworks_order_id` (orphan cleanup queries), `sku` (lookups)

**3b. Create `linnworks.order_extended_properties` table**
- File: `database/migrations/2026_03_28_000001_create_linnworks_order_extended_properties_table.php`
- Columns:
  - `uuid('id')->primary()`
  - `uuid('linnworks_order_id')` — FK cascade delete
  - `uuid('row_id')->unique()` — upsert key
  - `string('name')`, `text('value')`, `string('type')`
  - `timestampTz('create_date')->nullable()`, `timestampTz('last_update')->nullable()`, `string('updated_by')->nullable()`
  - `timestampsTz()`
- Index: `linnworks_order_id`

**3c. Add `notes` JSONB to `linnworks.orders`**
- File: `database/migrations/2026_03_28_000002_add_notes_to_linnworks_orders_table.php`
- `$table->jsonb('notes')->nullable()->after('folder_names')`

---

### Phase 4: Infrastructure — Models

**4a. Create `LinnworksOrderItemModel`**
- File: `app/Infrastructure/Linnworks/Models/LinnworksOrderItemModel.php`
- `$table = 'linnworks.order_items'`, `HasUuids`, `$guarded = []`
- Casts: booleans, floats, `immutable_datetime` for `added_date`, `array` for `additional_info`/`bin_racks`
- `belongsTo(LinnworksOrderModel::class)`

**4b. Create `LinnworksOrderExtendedPropertyModel`**
- File: `app/Infrastructure/Linnworks/Models/LinnworksOrderExtendedPropertyModel.php`
- Same pattern, `$table = 'linnworks.order_extended_properties'`

**4c. Update `LinnworksOrderModel`**
- File: `app/Infrastructure/Linnworks/Models/LinnworksOrderModel.php`
- Add `'notes' => 'array'` to casts
- Add `hasMany` relationships: `items()`, `extendedProperties()`

---

### Phase 5: Repository — Override `save()` with Child Sync

**File: `app/Infrastructure/Linnworks/Repositories/EloquentLinnworksOrderRepository.php`**

Override `save()` following the `EloquentStockItemRepository` pattern:

```php
public function save(object $entity): void
{
    $this->eloquentGateway->transact(function () use ($entity): void {
        // 1. Upsert order (including notes as JSONB)
        $this->eloquentGateway->upsertOne(
            modelClass: LinnworksOrderModel::class,
            attributes: $this->entityToAttributes($entity),
            uniqueBy: ['linnworks_order_id'],
        );

        // 2. Sync items: upsert by row_id + delete orphans
        $this->syncItems($entity);

        // 3. Sync extended properties: upsert by row_id + delete orphans
        $this->syncExtendedProperties($entity);
    }, attempts: 3);
}
```

**Private `syncItems(LinnworksOrder $order)` method:**
1. Map items to attribute arrays (include `linnworks_order_id => $order->orderId->value`)
2. If items not empty:
   - `$this->eloquentGateway->upsertMany(LinnworksOrderItemModel::class, $rows, ['row_id'])`
   - Collect RowIds: `$rowIds = array_map(fn($item) => $item->rowId->value, $order->items)`
   - `$this->eloquentGateway->deleteWhereNotIn(LinnworksOrderItemModel::class, 'linnworks_order_id', $order->orderId->value, 'row_id', $rowIds)`
3. If items empty: `$this->eloquentGateway->deleteWhere(LinnworksOrderItemModel::class, 'linnworks_order_id', $order->orderId->value)` — order lost all items

**Private `syncExtendedProperties(LinnworksOrder $order)` method:**
- Same upsert + delete-orphans pattern with `LinnworksOrderExtendedPropertyModel`

**Update `entityToAttributes()`:**
- Add notes serialization:
  ```php
  'notes' => array_map(static fn(LinnworksOrderNote $note) => [
      'order_note_id' => $note->orderNoteId->value,
      'note_date' => $note->noteDate->format('c'),
      'internal' => $note->internal,
      'note' => $note->note,
      'created_by' => $note->createdBy,
      'note_type_id' => $note->noteTypeId,
  ], $entity->notes),
  ```

**Remove `saveOrdersBulk()`** — this method was a bulk-specific shortcut that no longer applies. `saveMany()` from `RepositoryWriteInterface` provides the same contract (continue-on-failure, returns `SaveManyResult`) and calls `save()` per order:
- Remove `saveOrdersBulk()` from `LinnworksOrderRepositoryInterface`
- Remove `saveOrdersBulk()` from `EloquentLinnworksOrderRepository`

---

### Phase 6: UseCase + Interface Update

**File: `app/Application/Contracts/Linnworks/LinnworksOrderRepositoryInterface.php`**
- Remove `saveOrdersBulk()` method
- Remove the "No line items" doc comment
- Interface now just extends `RepositoryWriteInterface<LinnworksOrder>` with no additional methods (inherits `save()` and `saveMany()`)

**File: `app/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCase.php`**
- In `flushBuffer()`: change `$this->orderRepository->saveOrdersBulk($orders)` → `$this->orderRepository->saveMany($orders)`
- Update `@throws` docblock on `flushBuffer()` to include `DatabaseOperationFailedException` and `DuplicateRecordException` (from `saveMany()`)
- Remove unused `SaveManyResult` import if not referenced elsewhere (it's still the return type, so likely stays)

---

### Phase 7: Tests

- Update `SyncLinnworksOrdersUseCaseTest` — mock `saveMany()` instead of `saveOrdersBulk()`
- Add unit tests for new domain VOs (construction, edge cases)
- Add unit tests for `OrderItemResponse::toDomain()` (especially CompositeSubItems flattening)
- Add integration test for `EloquentLinnworksOrderRepository::save()` — verify parent+children persisted atomically, orphan cleanup works
- Update existing `OrderResponse` tests to include child entities

---

## File Change Summary

| # | File | Action |
|---|------|--------|
| 1 | `app/Domain/Linnworks/ValueObjects/LinnworksOrderItem.php` | Create |
| 2 | `app/Domain/Linnworks/ValueObjects/LinnworksOrderExtendedProperty.php` | Create |
| 3 | `app/Domain/Linnworks/ValueObjects/LinnworksOrderNote.php` | Create |
| 4 | `app/Domain/Linnworks/ValueObjects/LinnworksOrder.php` | Modify (add child collections) |
| 5 | `app/Infrastructure/Linnworks/Responses/OrderItemResponse.php` | Create |
| 6 | `app/Infrastructure/Linnworks/Responses/OrderItemAdditionalInfoResponse.php` | Create |
| 7 | `app/Infrastructure/Linnworks/Responses/OrderItemBinRackResponse.php` | Create |
| 8 | `app/Infrastructure/Linnworks/Responses/OrderExtendedPropertyResponse.php` | Create |
| 9 | `app/Infrastructure/Linnworks/Responses/OrderNoteResponse.php` | Create |
| 10 | `app/Infrastructure/Linnworks/Responses/OrderResponse.php` | Modify (add children + toDomain mapping) |
| 11 | `database/migrations/2026_03_28_000000_create_linnworks_order_items_table.php` | Create |
| 12 | `database/migrations/2026_03_28_000001_create_linnworks_order_extended_properties_table.php` | Create |
| 13 | `database/migrations/2026_03_28_000002_add_notes_to_linnworks_orders_table.php` | Create |
| 14 | `app/Infrastructure/Linnworks/Models/LinnworksOrderItemModel.php` | Create |
| 15 | `app/Infrastructure/Linnworks/Models/LinnworksOrderExtendedPropertyModel.php` | Create |
| 16 | `app/Infrastructure/Linnworks/Models/LinnworksOrderModel.php` | Modify (notes cast + relationships) |
| 17 | `app/Infrastructure/Linnworks/Repositories/EloquentLinnworksOrderRepository.php` | Modify (override save, add child sync, remove saveOrdersBulk) |
| 18 | `app/Application/Contracts/Linnworks/LinnworksOrderRepositoryInterface.php` | Modify (remove saveOrdersBulk) |
| 19 | `app/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCase.php` | Modify (saveOrdersBulk → saveMany) |
| 20 | Tests | Update existing + add new |

**No changes to:** `OrderClient`, `OrderClientInterface`, `LinnworksServiceProvider`, `DatabaseGatewayInterface`

---

## Reference Patterns

- **`EloquentStockItemRepository::save()`** (lines 47-106) — Exact pattern to follow for transactional parent+child sync
- **`EloquentGateway::upsertMany()`** (line 486) — Bulk upsert for child items within transaction
- **`EloquentGateway::deleteWhereNotIn()`** (line 800) — Orphan cleanup after upsert
- **`AbstractEloquentRepository::saveMany()`** (line 224) — Continue-on-failure iteration of `save()`

---

## Verification

1. `make lint` — PHPStan, Pint, Arkitect, Deptrac pass
2. `make test` — All existing tests pass + new tests
3. `php artisan migrate` — Migrations run cleanly against Supabase
4. Manual: Trigger a sync via tinker or queue, verify `linnworks.order_items` and `linnworks.order_extended_properties` tables populated correctly
5. Verify orphan cleanup: re-run sync after removing items from an order in Linnworks — orphaned rows should be deleted
