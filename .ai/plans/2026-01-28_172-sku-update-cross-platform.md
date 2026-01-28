# SKU Update Feature Implementation Plan

## Overview

Cross-platform SKU update system that synchronizes SKU changes between Linnworks (source of truth) and ShopWired, with audit logging and compensating transactions for failure recovery.

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Transaction strategy | Compensating transactions | 95% success rate, simple rollback (Linnworks update) |
| Audit approach | Simple created_at/completed_at | Implicit status, minimal writes, manual investigation for failures |
| Job serialization | `ShouldBeUnique` with fixed ID | One SKU update at a time (GetNewItemNumber generates sequential) |
| Chunk size | 1 | Each job = single SKU for error isolation |
| Schema | `operations` | Cross-platform operational data |
| ShopWired resolution | Use existing `ProductRepositoryInterface::getBasicProductBySku()` | Returns `Product\|ProductVariation`, Infrastructure handles branching |
| Update interface | `BasicProductUpdateClientInterface` with `UpdateBasicProductCommand` | Universal interface for ANY BasicProduct attribute updates (SKU, price, weight, etc.) |
| Domain value types | `Sku`, `Money` with validation | Strict validation at Domain level, reusable across layers |
| Tax handling | `Money` requires `TaxType` enum | Self-documenting prices with `toGross()`/`toNet()` conversions |

## Architecture: Universal BasicProduct Updates

This feature introduces a **universal update mechanism** for any `BasicProductInterface` attribute:

### Domain Value Types (new)

| Type | Purpose |
|------|---------|
| `TaxType` enum | `Inclusive`, `Exclusive`, `ZeroRated` - required for all Money |
| `Money` VO | Tax-aware price with `toGross()`, `toNet()`, configurable precision |
| `Sku` VO | Validated SKU (max 40 chars, alphanumeric + hyphens/underscores) |

### UpdateBasicProductCommand (Domain)

```php
final readonly class UpdateBasicProductCommand {
    public function __construct(
        public string $currentSku,        // identifier (required)
        public ?Sku $newSku = null,       // validated type
        public ?Money $price = null,
        public ?Money $costPrice = null,
        public ?Money $salePrice = null,
        public ?Weight $weight = null,
        public ?Gtin $gtin = null,
    ) {}
}
```

All nullable = partial updates. Only non-null fields get sent to APIs.

### BasicProductUpdateClientInterface (Application)

```php
interface BasicProductUpdateClientInterface {
    public function update(UpdateBasicProductCommand $command): void;
}
```

### Implementation Flow (Infrastructure)

```
1. Receive UpdateBasicProductCommand
2. Use ProductRepositoryInterface::getBasicProductBySku($command->currentSku)
3. Returns Product|ProductVariation (determines API endpoint)
4. Build payload from non-null command fields
5. Call appropriate endpoint:
   - Product: PUT /products/{id}
   - Variation: PUT /products/{productId}/variations/{variationId}
```

**Key insight**: Application layer doesn't know/care about product vs variation. Infrastructure handles branching internally based on the returned model type.

## Operation Flow

```
1. Insert audit record (operations.sku_changes)
2. Generate SKU if type=Generated (Linnworks GetNewItemNumber)
3. Update Linnworks (UpdateInventoryItemField)
4. Update ShopWired (PUT /products/{id})

On ShopWired failure:
├─ Compensate: Linnworks update (new_sku → old_sku)
├─ Set error_message on audit record
└─ Throw exception (job fails, can retry)

On success:
└─ Set completed_at on audit record
```

## Database Schema

### Migration: `operations.sku_changes`

```sql
CREATE SCHEMA IF NOT EXISTS operations;

CREATE TABLE operations.sku_changes (
    id BIGSERIAL PRIMARY KEY,
    stock_item_id UUID NOT NULL,           -- Linnworks identifier
    old_sku VARCHAR(255) NOT NULL,
    new_sku VARCHAR(255) NOT NULL,
    reason VARCHAR(50) NOT NULL,
    error_message TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);

-- CHECK constraint for reason values
ALTER TABLE operations.sku_changes
    ADD CONSTRAINT sku_changes_reason_check
    CHECK (reason IN ('shorten_long_sku', 'fix_sku_mismatch', 'standardize_format', 'merge_products', 'other'));

-- Index for finding incomplete changes
CREATE INDEX idx_sku_changes_incomplete ON operations.sku_changes (created_at)
    WHERE completed_at IS NULL;
```

## Files to Create

### Domain Layer - Shared Value Types (3 files)

| File | Purpose |
|------|---------|
| `app/Domain/ValueObjects/TaxType.php` | `Inclusive`, `Exclusive`, `ZeroRated` enum |
| `app/Domain/ValueObjects/Money.php` | Tax-aware price VO with `toGross()`, `toNet()` |
| `app/Domain/Catalog/Product/ValueObjects/Sku.php` | Validated SKU (max 40, alphanumeric) |

### Domain Layer - SKU Update Feature (5 files)

| File | Purpose |
|------|---------|
| `app/Domain/Inventory/Enums/SkuUpdateType.php` | `Provided` / `Generated` enum |
| `app/Domain/Inventory/Enums/SkuUpdateReason.php` | Reason enum matching DB CHECK |
| `app/Domain/Catalog/Product/Commands/UpdateBasicProductCommand.php` | Universal update command |
| `app/Domain/Exceptions/Inventory/SkuUpdateFailedException.php` | Update failure with context |
| `app/Domain/Exceptions/Inventory/SkuGenerationFailedException.php` | Generation failure |

### Application Layer (5 files)

| File | Purpose |
|------|---------|
| `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` | `updateSku()`, `getNewItemNumber()` |
| `app/Application/Contracts/Shopwired/BasicProductUpdateClientInterface.php` | Universal `update(UpdateBasicProductCommand)` |
| `app/Application/Contracts/Operations/SkuChangeRepositoryInterface.php` | Audit record CRUD |
| `app/Application/Inventory/UseCases/UpdateSkuUseCase.php` | Orchestration + compensation |
| `app/Application/Inventory/Results/SkuUpdateResult.php` | Success/failure result |

### Infrastructure Layer (5 files)

| File | Purpose |
|------|---------|
| `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` | Linnworks API implementation |
| `app/Infrastructure/Shopwired/Clients/BasicProductUpdateClient.php` | Universal update client (handles product vs variation) |
| `app/Infrastructure/Operations/Repositories/EloquentSkuChangeRepository.php` | DB persistence |
| `app/Infrastructure/Operations/Models/SkuChangeModel.php` | Eloquent model |
| `database/migrations/xxxx_create_operations_sku_changes_table.php` | Schema migration ✅ DONE |

### Presentation Layer (2 files)

| File | Purpose |
|------|---------|
| `app/Presentation/Jobs/Inventory/UpdateSkuJob.php` | Queue job with `ShouldBeUnique` |
| `app/Presentation/Console/Commands/UpdateSkusCommand.php` | Artisan command |

## Key Implementations

### SkuUpdateCommand (Domain)

```php
final readonly class SkuUpdateCommand
{
    public function __construct(
        public string $stockItemId,      // Linnworks GUID
        public string $oldSku,
        public ?string $newSku,          // null when type=Generated
        public SkuUpdateType $type,
        public SkuUpdateReason $reason,
    ) {
        Assert::uuid($stockItemId);
        Assert::notEmpty(trim($oldSku));

        if ($type === SkuUpdateType::Provided) {
            Assert::notNull($newSku);
            Assert::notEmpty(trim($newSku));
        }
    }
}
```

### InventoryUpdateClientInterface (Application Contract)

```php
interface InventoryUpdateClientInterface
{
    /** @throws ResourceNotFoundException|InvalidApiRequestException|AuthenticationExpiredException|ExternalServiceUnavailableException */
    public function updateSku(string $stockItemId, string $newSku): void;

    /** @throws AuthenticationExpiredException|ExternalServiceUnavailableException|InvalidApiResponseException */
    public function getNewItemNumber(): string;
}
```

### UpdateSkuUseCase (Application)

```php
public function execute(SkuUpdateCommand $command): SkuUpdateResult
{
    // 1. Resolve new SKU if needed
    $newSku = $command->type === SkuUpdateType::Generated
        ? $this->linnworksClient->getNewItemNumber()
        : $command->newSku;

    // 2. Create audit record
    $auditId = $this->skuChangeRepository->create(
        $command->stockItemId,
        $command->oldSku,
        $newSku,
        $command->reason,
    );

    // 3. Update Linnworks
    $this->linnworksClient->updateSku($command->stockItemId, $newSku);

    // 4. Get ShopWired productId from local DB
    $productId = $this->productRepository->getProductIdBySku($command->oldSku);

    // 5. Update ShopWired (with compensation on failure)
    try {
        $this->shopwiredClient->updateSku($productId, $newSku);
    } catch (Throwable $e) {
        $this->compensate($command, $newSku, $auditId, $e);
    }

    // 6. Mark complete
    $this->skuChangeRepository->markComplete($auditId);

    return SkuUpdateResult::success($command->oldSku, $newSku);
}

private function compensate(SkuUpdateCommand $command, string $newSku, int $auditId, Throwable $originalError): never
{
    $this->logger->warning('ShopWired failed, compensating Linnworks', [...]);

    try {
        $this->linnworksClient->updateSku($command->stockItemId, $command->oldSku);
    } catch (Throwable $compensationError) {
        $this->logger->critical('COMPENSATION FAILED - manual intervention required', [
            'stock_item_id' => $command->stockItemId,
            'linnworks_has' => $newSku,
            'shopwired_has' => $command->oldSku,
        ]);

        $this->skuChangeRepository->setError($auditId,
            "ShopWired failed + compensation failed: {$compensationError->getMessage()}");
    }

    $this->skuChangeRepository->setError($auditId, $originalError->getMessage());

    throw new SkuUpdateFailedException($command->stockItemId, $command->oldSku, $newSku,
        'shopwired', $originalError->getMessage(), $originalError);
}
```

### UpdateSkuJob (Presentation)

```php
final class UpdateSkuJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 120;
    public int $uniqueFor = 600;

    public function __construct(public readonly SkuUpdateCommand $command)
    {
        $this->onQueue('default');
    }

    /** Fixed ID = one SKU update job at a time globally */
    public function uniqueId(): string
    {
        return 'update-sku';
    }

    public function handle(UpdateSkuUseCase $useCase): void
    {
        // Exception handling per Presentation/CLAUDE.md patterns
    }
}
```

### UpdateSkusCommand (Artisan)

```php
protected $signature = 'inventory:update-skus
    {updates* : Format "old_sku:new_sku" or "old_sku:generate"}
    {--reason=other : shorten_long_sku|fix_sku_mismatch|standardize_format|merge_products|other}
    {--dry-run : Show what would be dispatched}';
```

Uses `DispatchesChunkedJobsTrait` with chunk size 1.

## Linnworks API Endpoints

### UpdateInventoryItemField

```
POST /api/Inventory/UpdateInventoryItemField
Content-Type: application/x-www-form-urlencoded

request={"inventoryItemId":"<GUID>","fieldName":"ItemNumber","fieldValue":"<NEW_SKU>"}
```

### GetNewItemNumber

```
GET /api/Inventory/GetNewItemNumber

Response: "SKU12345" (plain string)
```

## Verification

### Manual Testing

```bash
# Dry run
php artisan inventory:update-skus "TEST123:NEWTEST123" --reason=fix_sku_mismatch --dry-run

# Single update (provided SKU)
php artisan inventory:update-skus "TEST123:NEWTEST123" --reason=shorten_long_sku

# Single update (generated SKU)
php artisan inventory:update-skus "TEST123:generate" --reason=standardize_format

# Multiple updates
php artisan inventory:update-skus "SKU1:NEWSKU1" "SKU2:generate" --reason=fix_sku_mismatch
```

### Verify in systems

```bash
# Check Linnworks
railway ssh -s alz-core "php artisan tinker --execute=\"app(InventoryClientInterface::class)->getStockItemBySku('NEWTEST123')\""

# Check audit table
railway ssh -s alz-core "php artisan tinker --execute=\"DB::table('operations.sku_changes')->where('old_sku', 'TEST123')->first()\""
```

### Automated Tests

| Test | Coverage |
|------|----------|
| `SkuUpdateCommandTest` | Validation (UUID, empty SKU, type/newSku consistency) |
| `UpdateSkuUseCaseTest` | Happy path, compensation flow, generation flow |
| `InventoryUpdateClientTest` | HTTP mocking for Linnworks endpoints |
| `UpdateSkuJobTest` | Retry logic, unique constraint |

## Implementation Order

1. **Database**: Migration for `operations.sku_changes`
2. **Domain**: Enums, Command DTO, Exceptions
3. **Application**: Interfaces (contracts)
4. **Infrastructure - Linnworks**: `InventoryUpdateClient`
5. **Infrastructure - ShopWired**: Add `updateSku()` to existing client
6. **Infrastructure - DB**: `EloquentSkuChangeRepository`
7. **Application**: `UpdateSkuUseCase`
8. **Presentation**: Job + Command
9. **Tests**: Unit + Integration
