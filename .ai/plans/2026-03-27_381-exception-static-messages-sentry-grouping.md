# Exception Audit: Static Messages + Structured Context for Sentry Grouping

## Context

Every custom exception in the codebase embeds dynamic data (IDs, names, service names) directly in the exception message string. This prevents Sentry from grouping related issues — each unique ID creates a separate Sentry issue. For example, `ResourceNotFoundException` generates hundreds of distinct issues like "StockItem with ID '1005229' not found in Linnworks" instead of one grouped issue.

**Goal**: Static exception messages describing the error *type*, with all dynamic data in `context()` arrays that Sentry captures as structured metadata.

### Important: `getMessage()` Consumers

Two systems use `$e->getMessage()` to render user-facing output. These need updates as part of this refactoring:

1. **`InternalApiExceptionMapper::message()`** (line 132) — returns `$e->getMessage()` for `DomainException` subclasses in API responses. After refactoring, `ResourceNotFoundException` responses change from `"StockItem with ID '1005229' not found in Linnworks"` → `"Resource not found in external service"`. This is an improvement (stops leaking internal service names/IDs), but is a **visible API behavior change**. The mapper already returns fixed generic messages for most exception types (TransientApiFailure, PermanentApiFailure, DuplicateRecordException). The remaining DomainException cases that reach line 132 will now also be generic — consistent with the existing pattern. No mapper code changes needed.

2. **Console commands** (`VerifyApiConnectivityCommand`, `GenerateVariantSkusCommand`, `UpdateSkusCommand`, `TestPriceUpdateCommand`) display `$e->getMessage()` for operator debugging. After refactoring, they'll show generic messages. These commands should be updated to also display `$e->context()` when available, so operators still get the detail they need. Add this as a helper or inline check in each command's catch block.

---

## Phase 1: Add `context()` to Base Classes

Add `context(): array` returning `[]` to the two abstract base classes. This gives every exception in the hierarchy the method by default, so concrete classes just override it.

### Files to modify

| File | Change |
|------|--------|
| `app/Domain/Exceptions/DomainException.php` | Add `public function context(): array { return []; }` |
| `app/Infrastructure/Exceptions/InfrastructureException.php` | Add `public function context(): array { return []; }` |
| `app/Domain/Exceptions/Api/AbstractApiException.php` | Override: return `['service_name' => $this->serviceName]` |
| `app/Domain/Exceptions/Api/TransientApiFailure.php` | Override: merge `['retry_after' => $this->retryAfter]` with `parent::context()`. Change default message from `"External service '{$serviceName}' is unavailable"` → `'External service unavailable'` |

Note: `ValidationFailedException` already has `context()` — no conflict, it overrides the base.

---

## Phase 2: API Exceptions (PermanentApiFailure subclasses)

These 4 exceptions share a pattern: they accept `string $message` with a default value, then build `"{$serviceName}: {$message}"`. Refactor to store caller-provided message as `$detail` readonly property, pass static message to parent.

### Pattern for each

```php
public readonly string $detail;

public function __construct(
    string $serviceName,
    string $message = 'Static default here',  // keep param name for backward compat
    ?Throwable $previous = null,
) {
    $this->detail = $message;
    parent::__construct($serviceName, 'Static default here', $previous);
}

public function context(): array
{
    return [...parent::context(), 'detail' => $this->detail];
}
```

### Files

| Exception | Static Message | Extra Context |
|-----------|---------------|---------------|
| `AuthenticationExpiredException` | `'Authentication failed'` | `detail` |
| `InvalidApiRequestException` | `'API request validation failed'` | `detail` |
| `InvalidApiResponseException` | `'API response validation failed'` | `detail` |
| `PayloadSerializationException` | `'Failed to serialize payload'` | `detail` |

### Remaining PermanentApiFailure subclasses (structured properties, not `$message`)

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `ResourceNotFoundException` | `'Resource not found in external service'` | `service_name` (inherited), `resource_type`, `resource_id` |
| `UnexpectedApiResultException` | `'Unexpected result from external service'` | `service_name` (inherited), `reason` |

### TransientApiFailure subclasses

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `ExternalServiceUnavailableException` | `'External service unavailable'` | inherited (`service_name`, `retry_after`) — no override needed |
| `ResourceNotAvailableException` | `'Resource not yet available in external service'` | `service_name`, `retry_after` (inherited), `resource_type`, `resource_id` |

### PartialBatchFailureException (extends DomainException directly)

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `PartialBatchFailureException` | `'Partial batch failure'` | `service_name`, `failure_count` |

---

## Phase 3: Data Exceptions (AbstractDataException subclasses)

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `InvalidGtinException` | `'Invalid GTIN'` | `value`, `reason` |
| `InvalidSkuException` | `'Invalid SKU'` | `value`, `reason` |
| `MalformedFeedDataException` | `'Malformed feed data'` | `feed_name`, `reason` |
| `MissingRequiredDataException` | `'Required data not available'` | `data_type`, `operation`, `resolution` |
| `InsufficientDataException` | `'Insufficient data for operation'` | `context`, `requirement` |
| `InvalidEnumValueException` | `'Invalid enum value'` | `enum_class`, `enum_name` (computed via `class_basename()`), `value`, `context` |
| `MalformedStoredDataException` | `'Malformed stored data'` | `source`, `reason` |

Static factory methods (`InvalidSkuException::empty()`, `InvalidEnumValueException::unknownLabel()`, etc.) need no signature changes — they call the constructor which now uses the static message.

---

## Phase 4: Infrastructure Exceptions (AbstractInfrastructureException subclasses)

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `ConfigurationNotFoundException` | `'Required configuration not found'` | `config_name` |
| `DatabaseOperationFailedException` | `'Database operation failed'` | `operation`, `reason` |
| `DuplicateRecordException` | `'Duplicate record constraint violation'` | `table`, `constraint` |
| `StorageOperationFailedException` | `'Storage operation failed'` | `operation`, `path`, `reason` |
| `LockAcquisitionException` | `'Failed to acquire lock'` | `lock_name`, `timeout_seconds` |

---

## Phase 5: Inventory Exceptions

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `SkuGenerationFailedException` | `'Failed to generate new SKU'` | `reason` |
| `SkuUpdateFailedException` | `'SKU update failed'` | `old_sku`, `new_sku`, `failed_system`, `reason` |
| `InvalidTemplateException` | `'Invalid template stock item'` | `template_sku`, `reason` (promote `$reason` to readonly property) |

---

## Phase 6: Standalone Domain Exceptions

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `InvalidPurchaseOrderStatusTransitionException` | `'Invalid purchase order status transition'` | `from` (->value), `to` (->value) |
| `ProductIdentifierResolutionException` | `'Product identifier could not be resolved'` | `identifier`, `identifier_type` |
| `MissingVariationSkuException` | `'Product variation missing required SKU'` | `variation_id`, `product_external_id` |
| `InvalidCustomFieldValueException` | `'Custom field value type mismatch'` | `field_name`, `expected_type` (->value), `actual_type`, `raw_value_type` (via `get_debug_type()`) |
| `CustomFieldNotFoundException` | `'Custom field not found in registry'` | `field_name`, `item_type` (->value) |
| `CustomerServiceAgentNotFoundException` | `'Customer service agent not found'` | `email` |

### ProductIdentifierResolutionException — constructor change

Remove `string $message` param (only used by the 2 static factories). Factories update to not pass a message. Constructor becomes:
```php
public function __construct(
    public readonly string|int $identifier,
    public readonly string $identifierType,
) {
    parent::__construct('Product identifier could not be resolved');
}
```

---

## Phase 7: LogicException Subclasses (no shared base we control)

These extend PHP's `LogicException`, not `DomainException`, so add `context()` directly on each.

| Exception | Static Message | Context Keys |
|-----------|---------------|-------------|
| `InvalidConfigurationException` | `'Required configuration is missing or invalid'` | `config_key`, `detail` (from `$message` param, when non-empty) |
| `UnsupportedFieldException` | `'Unsupported field for entity'` | `field_name`, `entity_type` |

For `InvalidConfigurationException`: add `public readonly string $detail` property, store `$message` in it, pass static message to parent. Only include `detail` in context when non-empty.

---

## Phase 8: Infrastructure-Layer Exceptions (outside Domain hierarchy)

### InvalidGoogleAdsResponseException

Currently: no constructor, only static factories calling `new self($message)`.

Add constructor with properties:
```php
public function __construct(
    public readonly string $field,
    public readonly string $detail,
) {
    parent::__construct('Invalid Google Ads API response');
}

public function context(): array { return ['field' => $this->field, 'detail' => $this->detail]; }
```

Update factories (signatures unchanged):
- `::missingField($field, $context)` → `new self($field, "missing required field" . ($context !== '' ? " ({$context})" : ''))`
- `::invalidValue($field, $reason)` → `new self($field, $reason)`

### InvalidBingAdsResponseException

Same pattern. Add `$field` and `$detail` properties.

Update factories (signatures unchanged):
- `::missingColumn($column)` → `new self($column, 'missing required column')`
- `::invalidValue($field, $reason)` → `new self($field, $reason)`
- `::malformedCsv($reason)` → `new self('', $reason)`

### ApiRateLimitException

Add `public readonly string $detail` property (from `$message` param). Static message: `'API rate limit exceeded'`.

```php
public function context(): array { return ['retry_after' => $this->retryAfter, 'detail' => $this->detail]; }
```

### InvalidJwtClaimsException (extends RuntimeException directly)

Static message: `'Invalid JWT claims'`. Context: `['claim' => ..., 'reason' => ...]`. Factory signatures unchanged.

---

## Phase 9: Test Updates

Tests that assert on specific dynamic messages need updating. Change from asserting message content to asserting: (a) static message, and (b) context values.

### Unit exception tests (assert exact `getMessage()`)

| Test File | Change |
|-----------|--------|
| `tests/Unit/Domain/Exceptions/Api/PartialBatchFailureExceptionTest.php` | `'Partial batch failure'` + assert `context()` has `service_name`, `failure_count` |
| `tests/Unit/Domain/Exceptions/LockAcquisitionExceptionTest.php` | `'Failed to acquire lock'` + assert context |
| `tests/Unit/Domain/Exceptions/DatabaseOperationFailedExceptionTest.php` | `'Database operation failed'` + assert context |
| `tests/Unit/Domain/Exceptions/MissingRequiredDataExceptionTest.php` | `'Required data not available'` + assert context |
| `tests/Unit/Domain/Exceptions/InvalidTemplateExceptionTest.php` | `'Invalid template stock item'` + assert context |
| `tests/Unit/Domain/Exceptions/ConfigurationNotFoundExceptionTest.php` | `'Required configuration not found'` + assert context |
| `tests/Unit/Domain/Exceptions/DuplicateRecordExceptionTest.php` | `'Duplicate record constraint violation'` + assert context |
| `tests/Unit/Domain/CustomerService/Exceptions/CustomerServiceAgentNotFoundExceptionTest.php` | `'Customer service agent not found'` + assert context |
| `tests/Unit/Domain/Catalog/Product/Exceptions/ProductIdentifierResolutionExceptionTest.php` | `'Product identifier could not be resolved'` + assert context |

### Feature/integration tests (`expectExceptionMessage`)

| Test File | Old Message Pattern | New Static Message |
|-----------|--------------------|--------------------|
| `tests/Feature/Application/AdSpend/UseCases/SyncAdSpendUseCaseTest.php` (3 sites) | `"External service '...' is unavailable"` | `'External service unavailable'` |
| `tests/Feature/Infrastructure/AdSpend/Mixpanel/MixpanelClientTest.php` (3 sites) | `"External service 'Mixpanel' is unavailable"` | `'External service unavailable'` |
| `tests/Unit/Infrastructure/BingAds/BingAdsClientTest.php` (1 site) | `"External service 'Bing Ads' is unavailable"` | `'External service unavailable'` |
| `tests/Unit/Infrastructure/BingAds/BingAdsClientTest.php` (1 site) | `'Bing Ads: Authentication failed'` | `'Authentication failed'` |
| `tests/Unit/Infrastructure/GoogleAds/GoogleAdsClientTest.php` (2 sites) | `"External service 'Google Ads' is unavailable"` | `'External service unavailable'` |
| `tests/Unit/Application/Feeds/ProcessProductSearchFeedUseCaseTest.php` | `"External service 'Doofinder Feed' is unavailable"` | `'External service unavailable'` |
| `tests/Unit/Infrastructure/Feeds/DoofinderFeedProcessorTest.php` (2 sites) | `"External service 'Doofinder Feed' is unavailable"` | `'External service unavailable'` |
| `tests/Unit/Application/Inventory/Services/LinnworksStockItemCreatorServiceTest.php` | `"Failed to acquire lock 'sku-generation'"` | `'Failed to acquire lock'` |
| `tests/Unit/Infrastructure/Storage/S3StorageClientTest.php` (3 sites) | `"Storage ... failed for '...'"` | `'Storage operation failed'` |
| `tests/Unit/Infrastructure/Persistence/Repositories/EscalationsConfigRepositoryTest.php` | `"Required configuration 'hs_escalations' not found or disabled"` | `'Required configuration not found'` |
| `tests/Unit/Infrastructure/Jobs/Middleware/HandleApiExceptionsTest.php` | `'Unexpected database error'` | Check if this is a Throwable message (not our exception) — may not need change |

Additional tests to find via grep during implementation: any `expectExceptionMessage` or `assertSame/assertEquals` matching dynamic patterns from the exception constructors listed above.

---

## Phase 10: Documentation Updates

### `app/Domain/CLAUDE.md` — Exception Design Rules section (line 16)

Add after existing rules:
```
- Exception messages MUST be static strings (no interpolated IDs/names/dynamic data) — dynamic data goes in `context()` via readonly properties, enabling Sentry grouping
```

### `CLAUDE.md` (root) — Creating Exceptions section (line ~243)

Add:
```
- **Static messages**: Exception messages must be static (no interpolated dynamic data). Pass dynamic data as readonly constructor properties and return them from `context()` for Sentry grouping.
```

### `app/Infrastructure/CLAUDE.md` — Exception Handling section

Add:
```
- **Static messages** — When throwing domain exceptions, keep messages static. Pass dynamic details (IDs, field names, error reasons) as constructor parameters — they become context, not message content.
```

### `.claude/commands/sweep.md` — Exception Handling section (after line 64)

Add:
```
- **Static messages + context** — Exception messages must be static strings (no interpolated IDs/names/dynamic data). Dynamic data belongs in readonly properties returned via `context()` for Sentry grouping.
```

---

## Implementation Order

1. Base classes (Phase 1) — establishes the pattern
2. API exceptions (Phase 2) — largest group, most test impact
3. Data exceptions (Phase 3)
4. Infrastructure exceptions (Phase 4)
5. Inventory exceptions (Phase 5)
6. Standalone domain exceptions (Phase 6)
7. LogicException subclasses (Phase 7)
8. Infrastructure-layer exceptions (Phase 8)
9. Test updates (Phase 9) — after each phase, update related tests
10. Documentation (Phase 10)

Run `make fix && make lint && make test` after completing phases 1-8 (exception classes), then again after phase 9 (tests), then after phase 10 (docs). Batching the exception class changes avoids 8 separate 7-minute lint cycles for independent changes.

---

## Phase 11: Console Command Updates

Update Artisan commands that display `$e->getMessage()` to also show context when available. Pattern:

```php
$message = $e->getMessage();
if (method_exists($e, 'context') && $e->context() !== []) {
    $message .= ' — ' . json_encode($e->context(), JSON_THROW_ON_ERROR);
}
$this->error("Failed: {$message}");
```

Files:
- `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php` (~12 catch blocks)
- `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php` (5 catch blocks)
- `app/Presentation/Console/Commands/UpdateSkusCommand.php` (1 catch block)

Note: Dev-only commands (`TestSlackNotificationCommand`, `TestPriceUpdateCommand`) are lower priority — update if time allows.

---

## Verification

1. `make lint` — ensure PHPStan/Pint/Arkitect/Deptrac pass
2. `make test` — all tests pass with updated message assertions
3. Spot-check: grep for string interpolation in exception constructors (`"\$.*"` patterns in `app/Domain/Exceptions/`, `app/Infrastructure/Exceptions/`)
4. Verify no exception constructor still embeds dynamic data in the message passed to `parent::__construct()`

---

## Scope Exclusions

- **PHP built-in exceptions** (`InvalidArgumentException`, `RuntimeException`, `LogicException`) thrown at ~30 call sites with dynamic messages — these are NOT our custom exception classes and are outside scope. They use PHP's standard exception mechanism and most are config validation guards (InvalidArgumentException in Config classes) that don't reach Sentry.
- **`ValidationFailedException`** — already has `context()` and its `$reason` is the message by design (validators produce human-readable reasons). Left as-is. **Known gap**: validation reasons are dynamic caller-provided text, so these won't group in Sentry either — but that's the intended tradeoff for user-facing validation messages.
- **`HookFailException`** in DevTools — development-only, not reported to Sentry.
- **`InsufficientDataException`/`InvalidEnumValueException` `context` key naming** — these have a `$context` property, so their `context()` method returns `['context' => $this->context, ...]`. The key name is confusing but functional. Consider renaming to `description` during implementation if it reads better.
