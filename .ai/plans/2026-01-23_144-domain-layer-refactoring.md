# Domain Layer Refactoring Plan

## Overview

Two refactoring tasks for the Domain layer:
1. **Move `ProductRating.php`** to consolidate product-related domain objects
2. **Reorganize exceptions into subdirectories** with marker base classes for type hierarchy

---

## Task 1: Move ProductRating

**From:** `app/Domain/Product/ValueObjects/ProductRating.php`
**To:** `app/Domain/Catalog/Product/ValueObjects/ProductRating.php`

### Changes Required
1. Move file to new location (use `git mv` for history)
2. Update namespace: `App\Domain\Product\ValueObjects` → `App\Domain\Catalog\Product\ValueObjects`
3. Update all imports
4. Delete empty `Domain/Product/` directory

### Files to Update (5 files)
- `app/Domain/Product/ValueObjects/ProductRating.php` (move)
- `app/Infrastructure/ReviewsIo/ReviewsIoClient.php`
- `app/Infrastructure/ReviewsIo/Responses/RatingResponse.php`
- `app/Application/Contracts/ReviewsIoClientInterface.php`
- `tests/Feature/Infrastructure/Api/ReviewsIoClientTest.php`
- `tests/Unit/Domain/Product/ValueObjects/ProductRatingTest.php` (move to `tests/Unit/Domain/Catalog/Product/ValueObjects/`)

---

## Task 2: Exception Reorganization (Simplified)

### Scope Change
After analysis, we're simplifying to **subdirectories + marker base classes only**.

**Why:** Job exception handling needs specific catches for proper logging. Shared constructors provide marginal benefit for significant refactoring effort.

### Prerequisites
**Update PHPArkitect rules first** — current rules don't allow subdirectories within `Exceptions/`.

In `phparkitect.php` (around line 543), update from:
```php
'App\Domain\Exceptions',
'App\Domain\*\Exceptions',
```
To:
```php
'App\Domain\Exceptions',
'App\Domain\Exceptions\*',      // NEW: Allow subdirectories
'App\Domain\*\Exceptions',
```

### Target Structure

```
Domain/Exceptions/
├── DomainException.php                    # Base (unchanged)
├── Api/
│   ├── ApiException.php                   # NEW marker class (empty)
│   ├── AuthenticationExpiredException.php
│   ├── ExternalServiceUnavailableException.php
│   ├── InvalidApiRequestException.php
│   ├── InvalidApiResponseException.php
│   ├── PayloadSerializationException.php
│   ├── ResourceNotFoundException.php
│   └── UnexpectedApiResultException.php
├── Data/
│   ├── DataException.php                  # NEW marker class (empty)
│   ├── InvalidGtinException.php
│   ├── MalformedFeedDataException.php
│   └── MissingRequiredDataException.php
├── Infrastructure/
│   ├── InfrastructureException.php        # NEW marker class (empty)
│   ├── ConfigurationNotFoundException.php
│   ├── DatabaseOperationFailedException.php
│   ├── DuplicateRecordException.php
│   ├── StockUpdateFailedException.php
│   └── StorageOperationFailedException.php
└── InvalidConfigurationException.php      # Keep at root (extends LogicException)
```

### New Base Classes (Marker Classes Only)

```php
// Api/ApiException.php
abstract class ApiException extends DomainException {}

// Data/DataException.php
abstract class DataException extends DomainException {}

// Infrastructure/InfrastructureException.php
abstract class InfrastructureException extends DomainException {}
```

**No constructor changes required** — exceptions keep their existing constructors, just change `extends DomainException` to `extends ApiException` (etc).

### Migration Steps

1. **Update PHPArkitect rules** (prerequisite)
   - Add `'App\Domain\Exceptions\*'` to allowed namespaces

2. **Create subdirectories and marker base classes**
   - Create `Api/`, `Data/`, `Infrastructure/` directories
   - Create empty abstract base classes

3. **Move exceptions to subdirectories** (use `git mv`)
   - Move each exception to appropriate subdirectory
   - Update namespace in each file
   - Change `extends DomainException` to `extends ApiException` (etc)

4. **Update all imports** (~170 files, 458 import statements)
   - Search/replace import paths
   - **Include config files** — `config/sentry.php` imports `AuthenticationExpiredException`

5. **Optional: Add fallback catches to jobs**
   - Add `catch (ApiException $e)` as fallback for unhandled future exceptions
   - Keep existing specific catches for proper logging

### Impact Assessment

**Files requiring import updates:** ~170 files
- Infrastructure layer: ~80 files
- Application layer: ~40 files
- Presentation layer: ~30 files
- Tests: ~20 files
- **Config files:** `config/sentry.php`

### Feature-Specific Exceptions (No Change)

These stay in their current locations:
- `Domain/Catalog/CustomFields/Exceptions/CustomFieldNotFoundException.php`
- `Domain/Catalog/CustomFields/Exceptions/InvalidCustomFieldValueException.php`
- `Domain/Catalog/Product/Exceptions/MissingVariationSkuException.php`
- `Domain/CustomerService/Exceptions/CustomerServiceAgentNotFoundException.php`

---

## Execution Order

1. **Task 1 first** (quick win, ~5 min)
2. **Task 2** (simplified refactor, ~20-30 min)
   - Step 2a: Update PHPArkitect rules
   - Step 2b: Create directories and marker base classes
   - Step 2c: Move exceptions (git mv for history)
   - Step 2d: Update extends + namespaces in moved files
   - Step 2e: Update all imports across codebase
   - Step 2f: Run tests and lints

---

## Verification

### After Task 1
```bash
# Verify no references to old namespace
grep -r "App\\Domain\\Product\\ValueObjects" app/ tests/

# Run tests
make test
```

### After Task 2
```bash
# Verify no references to old exception paths
grep -r "use App\\Domain\\Exceptions\\AuthenticationExpiredException" app/
# (should find none - all should be Api/AuthenticationExpiredException)

# Run full validation
make lint
make test
```

### Manual Verification
- Check one job (e.g., `SyncShopwiredOrdersJob`) still catches exceptions correctly
- Verify IDE autocomplete works for new paths

---

## Rollback

Both tasks are pure refactoring with no behavioral changes. If issues arise:
- Revert commits
- No data migration or config changes required
