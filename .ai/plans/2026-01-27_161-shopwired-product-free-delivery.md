# Plan: Add Free Delivery to Product

## Overview

Enable setting the `free_delivery` custom field on ShopWired products via console command and HTTP API. Build generic custom field update infrastructure for reuse.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| PUT method location | `ShopwiredHttpTransport` | Follows existing `get()`/`post()` pattern |
| Product Identifier Resolver | Infrastructure layer | Performs DB lookups (variation SKU → parent) |
| Fetch-Merge-PUT orchestration | Application orchestrates | Application decides WHAT, Infrastructure handles HOW |
| FreeDeliveryType | Domain enum + DB validation test | Type-safe with drift detection |
| Custom field name | `free_delivery` (snake_case) | Confirmed by user |
| HTTP Auth | Supabase JWT | Consistent with other external APIs |

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│ Presentation                                                         │
│  ├── SetProductFreeDeliveryCommand (console)                        │
│  ├── ProductCustomFieldController (HTTP, Supabase JWT)              │
│  └── Jobs/Shopwired/SetProductFreeDeliveryJob                       │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Application                                                          │
│  ├── SetProductFreeDeliveryUseCase                                  │
│  └── Contracts/Shopwired/                                           │
│       ├── ProductIdentifierResolverInterface                        │
│       └── ProductCustomFieldUpdateClientInterface                   │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Infrastructure                                                       │
│  ├── ShopwiredHttpTransport (add put() method)                      │
│  ├── Services/ProductIdentifierResolver                             │
│  └── Clients/ProductCustomFieldUpdateClient (translates None→null) │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Domain                                                               │
│  ├── Enums/FreeDeliveryType (None, Standard, Express)               │
│  ├── Commands/SetFreeDeliveryCommand (pure business request)        │
│  └── Exceptions/ProductIdentifierResolutionException                │
└─────────────────────────────────────────────────────────────────────┘
```

## Implementation Phases

### Phase 1: API Overwrite Verification (CRITICAL FIRST)

Before implementing, verify the ShopWired custom field overwrite behavior.

**Test procedure:**
1. Fetch product 5585518 custom fields
2. Set custom field A to value X
3. Set custom field B to value Y (WITHOUT including A in request)
4. Check if A was preserved or lost

If A is lost → fetch-merge-PUT pattern is mandatory.
If A is preserved → simpler direct update possible.

**File:** `tests/Integration/Infrastructure/Shopwired/ProductCustomFieldOverwriteTest.php`

### Phase 2: Foundation (Domain + Infrastructure Transport)

**2.1 Domain Layer**

| File | Description |
|------|-------------|
| `app/Domain/Catalog/Product/Enums/FreeDeliveryType.php` | Backed enum: `None`, `Standard`, `Express` with `fromString()` factory |
| `app/Domain/Catalog/Product/Commands/SetFreeDeliveryCommand.php` | Pure business request: `identifier` (string\|int), `freeDeliveryType` |
| `app/Domain/Catalog/Product/Exceptions/ProductIdentifierResolutionException.php` | Named constructors: `skuNotFound()`, `productIdNotFound()` |

**FreeDeliveryType Enum:**
```php
enum FreeDeliveryType: string
{
    case None = 'none';        // Business concept - clears the field in ShopWired
    case Standard = 'Standard'; // ShopWired allowed value
    case Express = 'Express';   // ShopWired allowed value
}
```

**None Translation (in UseCase, not Infrastructure):**
- UseCase translates `None` → `null` before calling Infrastructure
- `Standard`/`Express` → sent as-is via `->value`

**2.2 Infrastructure Transport**

| File | Change |
|------|--------|
| `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` | Add `put()` method (copy `post()` pattern, change to `'PUT'`) |

### Phase 3: Infrastructure Services

**3.1 Product Identifier Resolver**

| File | Description |
|------|-------------|
| `app/Application/Contracts/Shopwired/ProductIdentifierResolverInterface.php` | Interface: `resolveToParentProductId(string\|int $identifier): int` |
| `app/Infrastructure/Shopwired/Services/ProductIdentifierResolver.php` | Implementation with resolution logic |

**Resolution Logic:**
```
1. If int → verify product exists, return as-is
2. If string (SKU):
   a. Query ProductModel by sku → return external_id
   b. Query ProductVariationModel by sku → return product_external_id
   c. Throw ProductIdentifierResolutionException::skuNotFound()
```

**3.2 Custom Field Update Client**

| File | Description |
|------|-------------|
| `app/Application/Contracts/Shopwired/ProductCustomFieldUpdateClientInterface.php` | Interface: `updateCustomFields(int $productId, array $customFields): void` |
| `app/Infrastructure/Shopwired/Clients/ProductCustomFieldUpdateClient.php` | Implementation with fetch-merge-PUT |

**Update Logic:**
```
1. Fetch product via ProductClientInterface::getProductById()
2. Get existing rawCustomFields
3. Merge new values (array_merge preserves existing, overwrites specified)
4. PUT to products/{id} with {customFields: merged}
```

### Phase 4: Application Layer

| File | Description |
|------|-------------|
| `app/Application/Shopwired/Results/SetFreeDeliveryResult.php` | Result: `total`, `succeeded`, `failed`, `failures` (list with identifier + error + requestedType) |
| `app/Application/Shopwired/UseCases/SetProductFreeDeliveryUseCase.php` | Batch processing with continue-on-failure semantics |

Note: `SetFreeDeliveryCommand` lives in Domain as a pure business request object.
Note: Job lives in Presentation layer (see Phase 5).

**UseCase None Translation:**
```php
// UseCase translates None to null before calling Infrastructure
$apiValue = $command->freeDeliveryType === FreeDeliveryType::None
    ? null
    : $command->freeDeliveryType->value;

$this->updateClient->updateCustomFields($productId, [
    'free_delivery' => $apiValue,
]);
```

**Chunking Strategy (entry points):**
```php
// Console/Controller dispatches chunks of 25 items
$commands->chunk(25)->each(fn($chunk) =>
    SetProductFreeDeliveryJob::dispatch($chunk->all())
);
```

**Use Case Logic:**
```php
public function execute(array $commands): SetFreeDeliveryResult
{
    foreach ($commands as $command) {
        try {
            $productId = $this->resolver->resolveToParentProductId($command->identifier);

            // Translate None to null (UseCase responsibility)
            $apiValue = $command->freeDeliveryType === FreeDeliveryType::None
                ? null
                : $command->freeDeliveryType->value;

            $this->updateClient->updateCustomFields($productId, [
                'free_delivery' => $apiValue,
            ]);
            $succeeded++;
        } catch (ProductIdentifierResolutionException
               | ResourceNotFoundException
               | ExternalServiceUnavailableException
               | InvalidApiRequestException $e) {
            // All failures recorded, batch continues
            $failures[] = [
                'identifier' => $command->identifier,
                'error' => $e->getMessage(),
                'requestedType' => $command->freeDeliveryType,
            ];
        }
    }
    return new SetFreeDeliveryResult($total, $succeeded, count($failures), $failures);
}
```

### Phase 5: Presentation Layer

**5.1 Console Command**

| File | Description |
|------|-------------|
| `app/Presentation/Console/Commands/SetProductFreeDeliveryCommand.php` | Signature: `shopwired:set-free-delivery {identifiers*} {--type=Standard} {--dry-run}` |

**5.2 Queue Job**

| File | Description |
|------|-------------|
| `app/Presentation/Jobs/Shopwired/SetProductFreeDeliveryJob.php` | Queue job with smart failure handling (follows codebase patterns) |

**Job Implementation (follows Presentation/CLAUDE.md patterns):**
```php
final class SetProductFreeDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 300;

    /** @var array<int> Exponential backoff */
    public array $backoff = [30, 60, 120, 300];

    public function __construct(
        public readonly array $commands,  // list<SetFreeDeliveryCommand>
    ) {
        $this->onQueue('default');
    }

    public function handle(SetProductFreeDeliveryUseCase $useCase): void
    {
        try {
            $result = $useCase->execute($this->commands);

            if ($result->failed === 0) return;  // All succeeded

            if ($result->succeeded === 0) {
                // Total failure - throw to trigger job retry
                throw new AllItemsFailedException($result);
            }

            // Partial failure - re-queue ONLY failed items
            Log::warning('Partial free delivery update failure', [
                'succeeded' => $result->succeeded,
                'failed' => $result->failed,
                'failures' => $result->failures,
            ]);

            $failedCommands = $this->extractFailedCommands($result);
            if ($failedCommands !== []) {
                self::dispatch($failedCommands)->delay(now()->addMinutes(5));
            }

        } catch (AuthenticationExpiredException | InvalidApiResponseException $e) {
            // Permanent failure - don't waste retries
            Log::critical('Free delivery update permanent failure', [
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);

        } catch (ExternalServiceUnavailableException $e) {
            // Use API's retry-after hint
            Log::warning('ShopWired unavailable', ['retry_after' => $e->retryAfter]);
            $this->release($e->retryAfter);

        } catch (\Throwable $e) {
            // Unexpected - log critical and fail
            Log::critical('Unexpected free delivery job error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }

    /** @return list<SetFreeDeliveryCommand> */
    private function extractFailedCommands(SetFreeDeliveryResult $result): array
    {
        return array_map(
            fn(array $failure) => new SetFreeDeliveryCommand(
                $failure['identifier'],
                $failure['requestedType'],
            ),
            $result->failures,
        );
    }
}
```

**5.3 HTTP Controller**

| File | Description |
|------|-------------|
| `app/Presentation/Http/Controllers/Shopwired/ProductCustomFieldController.php` | Single action: `setFreeDelivery()` |
| `app/Presentation/Http/Requests/SetFreeDeliveryRequest.php` | Validates `updates` array structure |

**Request/Response:**
```json
// POST /api/shopwired/products/free-delivery
// Request
{
  "updates": [
    {"identifier": "SKU123", "type": "Standard"},
    {"identifier": 5585518, "type": "Express"}
  ]
}

// Response (200/207/422)
{
  "total": 2,
  "succeeded": 1,
  "failed": 1,
  "failures": [
    {"identifier": "SKU123", "error": "SKU not found"}
  ]
}
```

**5.4 Routes**

| File | Change |
|------|--------|
| `routes/api.php` | Add route within Supabase JWT middleware group |

### Phase 6: Wiring

| File | Change |
|------|--------|
| `app/Providers/ShopwiredServiceProvider.php` | Bind new interfaces to implementations |
| `app/Infrastructure/Shopwired/ShopwiredClientFactory.php` | Add factory methods for new clients |

### Phase 7: Testing

**Unit Tests:**
- `tests/Unit/Domain/Catalog/Product/Enums/FreeDeliveryTypeTest.php`
- `tests/Unit/Application/Shopwired/UseCases/SetProductFreeDeliveryUseCaseTest.php`

**Integration Tests:**
- `tests/Integration/Infrastructure/Shopwired/ProductIdentifierResolverTest.php`
- `tests/Integration/Infrastructure/Shopwired/ProductCustomFieldUpdateClientTest.php`

**Feature Tests:**
- `tests/Feature/Console/SetProductFreeDeliveryCommandTest.php`
- `tests/Feature/Http/ProductCustomFieldControllerTest.php`

**Validation Test:**
- `tests/Integration/Domain/FreeDeliveryTypeDbValidationTest.php` - Verifies `Standard`/`Express` match DB `allowed_values` (excludes `None` - our business concept)

## Environment Variables

Add to `.env.example`:
```
SHOPWIRED_TEST_PRODUCT_ID=5585518
```

## Files Summary

### New Files (21)

**Domain (4):**
- `app/Domain/Catalog/Product/Enums/FreeDeliveryType.php`
- `app/Domain/Catalog/Product/Commands/SetFreeDeliveryCommand.php`
- `app/Domain/Catalog/Product/Exceptions/ProductIdentifierResolutionException.php`
- `app/Domain/Catalog/Product/Exceptions/AllItemsFailedException.php`

**Application (4):**
- `app/Application/Contracts/Shopwired/ProductIdentifierResolverInterface.php`
- `app/Application/Contracts/Shopwired/ProductCustomFieldUpdateClientInterface.php`
- `app/Application/Shopwired/Results/SetFreeDeliveryResult.php`
- `app/Application/Shopwired/UseCases/SetProductFreeDeliveryUseCase.php`

**Infrastructure (2):**
- `app/Infrastructure/Shopwired/Services/ProductIdentifierResolver.php`
- `app/Infrastructure/Shopwired/Clients/ProductCustomFieldUpdateClient.php`

**Presentation (4):**
- `app/Presentation/Console/Commands/SetProductFreeDeliveryCommand.php`
- `app/Presentation/Jobs/Shopwired/SetProductFreeDeliveryJob.php`
- `app/Presentation/Http/Controllers/Shopwired/ProductCustomFieldController.php`
- `app/Presentation/Http/Requests/SetFreeDeliveryRequest.php`

**Tests (7):**
- `tests/Integration/Infrastructure/Shopwired/ProductCustomFieldOverwriteTest.php`
- `tests/Unit/Domain/Catalog/Product/Enums/FreeDeliveryTypeTest.php`
- `tests/Unit/Application/Shopwired/UseCases/SetProductFreeDeliveryUseCaseTest.php`
- `tests/Integration/Infrastructure/Shopwired/ProductIdentifierResolverTest.php`
- `tests/Unit/Presentation/Jobs/Shopwired/SetProductFreeDeliveryJobTest.php`
- `tests/Feature/Console/SetProductFreeDeliveryCommandTest.php`
- `tests/Feature/Http/ProductCustomFieldControllerTest.php`

### Modified Files (3)

- `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` - Add `put()` method
- `app/Providers/ShopwiredServiceProvider.php` - Bind new interfaces
- `routes/api.php` - Add new route

## Verification

1. **API Overwrite Test**: Run `ProductCustomFieldOverwriteTest` to confirm behavior
2. **Unit Tests**: `make test-quick` for domain/application tests
3. **Integration Tests**: `make test` for full suite including API tests
4. **Manual Verification**:
   ```bash
   php artisan shopwired:set-free-delivery SKU123 --type=Express --dry-run
   php artisan shopwired:set-free-delivery SKU123 --type=Express
   ```
5. **HTTP API Test** (after route added):
   ```bash
   curl -X POST http://localhost/api/shopwired/products/free-delivery \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"updates": [{"identifier": "SKU123", "type": "Express"}]}'
   ```

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Custom field overwrite destroys data | Phase 1 verification test BEFORE implementation |
| SKU resolution ambiguity | Clear resolver logic: products first, then variations |
| Enum drift from DB | Validation test catches mismatches in CI |
| Production data corruption | Test product ID in env var, never hardcoded |
