# Domain Validation System — Implementation Plan

## Context

Validation logic is currently scattered across UseCases as ad-hoc checks with inline exception throws. The design in `.ai/reports/domain-validator-report.md` defines a unified validation system with shared contracts, traits, and mechanical enforcement. This plan implements the core infrastructure and one real validator (`ProductSkuValidator`) as the first consumer.

**Important:** Infrastructure code (contracts, traits, exception) should be taken from `domain-validator-report.md` exactly — adapt only namespaces, interface naming (`*Interface` suffix), and exception property visibility (`public readonly`). The `SkuBelongsToProductValidator` must be adapted from the user's existing code pattern (lookup-map approach) since the report's Product API (`->skus()->contains()`) doesn't exist in this codebase.

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Infrastructure location | `Domain/Shared/Validation/` | Follows the report; introduces `Shared/` concept for cross-cutting infrastructure |
| Interface naming | `*Interface` suffix | Codebase convention enforced by PHPArkitect Rule 6 |
| Exception properties | `public readonly` + getter methods | Matches codebase convention (AllItemsFailedException pattern) while keeping `reason()`/`context()` methods for API consistency with DescribableValidationResultInterface |
| `Product::allSkus()` | Add to Product | Encapsulates SKU collection, useful beyond validation |
| Aggregate example | Don't create one | Infrastructure + docs explain how; no forced example |
| 2nd simple validator | Don't create one | `ProductSkuValidator` is sufficient as first consumer |
| Report linting rules (1-6) | Defer | Too few validators to justify; document in validation guide |
| `#[Override]` enforcement | Not needed | `final class` prevents subclassing; trait override impossible |

## Implementation Order

### Phase 1: PHPArkitect Rule Updates

**Modify:** `phparkitect.php`

**1a. Rule 9** — Domain organization (`ResideInOneOfTheseNamespaces` list, ~line 610):
Add `'App\Domain\*\Validators'` after the existing `'App\Domain\*\Events'` entry. This unblocks all Domain validator classes.

No other Rule 9 changes needed — `App\Domain\*\Contracts` and `App\Domain\*\Concerns` already match `App\Domain\Shared\Validation\Contracts` and `App\Domain\Shared\Validation\Concerns` (PHPArkitect `*` matches multiple namespace segments).

**1b. Application naming rule** (~line 391):
Add `'*Validator'` to the `MatchOneOfTheseNames` list. This proactively unblocks future Application-layer validators (described in the report §Application-Layer Validators). `*Result` is already in the list.

### Phase 2: Exception

**Create:** `app/Domain/Exceptions/ValidationFailedException.php`

Code: copy from report §Exception, adjusting:
- Namespace → `App\Domain\Exceptions`
- Extends `DomainException` (the project's `App\Domain\Exceptions\DomainException`)
- `final class` (NOT `readonly` — exceptions extend RuntimeException which isn't readonly-compatible)
- Properties: use `public readonly` (codebase convention) instead of report's `private readonly`. Keep the `reason()` and `context()` getter methods too — belt and suspenders, mirrors DescribableValidationResultInterface API so `$result->reason()` and `$exception->reason()` are consistent

### Phase 3: Contracts

**Create:** `app/Domain/Shared/Validation/Contracts/ValidatorInterface.php`

Code: copy from report §Validator interface, adjusting:
- Namespace → `App\Domain\Shared\Validation\Contracts`
- Name → `ValidatorInterface`
- Return type → `DescribableValidationResultInterface`

**Create:** `app/Domain/Shared/Validation/Contracts/DescribableValidationResultInterface.php`

Code: copy from report §DescribableValidationResult interface, adjusting:
- Namespace → `App\Domain\Shared\Validation\Contracts`
- Name → `DescribableValidationResultInterface`
- Import → `App\Domain\Exceptions\ValidationFailedException`

### Phase 4: Traits

**Create:** `app/Domain/Shared/Validation/Concerns/ThrowsOnValidationFailure.php`

Code: copy from report §ThrowsOnValidationFailure trait, adjusting:
- Namespace → `App\Domain\Shared\Validation\Concerns`
- Import → `App\Domain\Exceptions\ValidationFailedException`

**Create:** `app/Domain/Shared/Validation/Concerns/AggregatesChildResults.php`

Code: copy from report §AggregatesChildResults trait, adjusting:
- Namespace → `App\Domain\Shared\Validation\Concerns`
- Import → `App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface`

### Phase 5: Verify infrastructure passes linters

Run `make lint` to confirm Phases 1-4 pass PHPArkitect, PHPStan, Deptrac.

### Phase 6: Product.allSkus()

**Modify:** `app/Domain/Catalog/Product/ValueObjects/Product.php`

Add method (after existing `hasVariations()`):
```php
/**
 * Get all SKUs from this product (master + variations) as typed Sku objects.
 *
 * @return list<Sku>
 */
public function allSkus(): array
```

Logic: collect `$this->sku` (if not null, wrap with `Sku::fromTrusted()`) + variation SKUs (if variations not null, iterate, wrap non-null `$v->sku`). Return `array_values()`.

### Phase 7: ProductSkuValidator + Result

**Create:** `app/Domain/Catalog/Product/Validators/SkuBelongsToProductValidator.php`

Based on report §SKU Belongs to Product, but adapted:
- Namespace → `App\Domain\Catalog\Product\Validators`
- Uses `App\Domain\Catalog\Product\ValueObjects\Product` and `Sku`
- Implements `ValidatorInterface`
- Constructor: `Product $product`, `array $requiredSkus` (`@param list<Sku>`)
- `validate(): SkuBelongsToProductResult` (covariant return)
- Uses `$this->product->allSkus()` to build lookup, filters missing

**Create:** `app/Domain/Catalog/Product/Validators/SkuBelongsToProductResult.php`

Based on report §SkuValidationResult, adapted:
- Namespace → `App\Domain\Catalog\Product\Validators`
- `final readonly class implements DescribableValidationResultInterface`
- `use ThrowsOnValidationFailure;`
- Constructor: `array $missingSkus = []` (`@param list<Sku>`)
- `passed()`: `$this->missingSkus === []`
- `failed()`: `! $this->passed()`
- `reason()`: descriptive string with count
- `context()`: `['missing_skus' => array_map(fn => $s->value, ...)]` (strings for serialization)
- `missingSkus(): array` — domain-specific accessor

### Phase 8: Tests

**Tests for infrastructure (anonymous class patterns):**

| File | Tests |
|------|-------|
| `tests/Unit/Domain/Exceptions/ValidationFailedExceptionTest.php` | reason(), getMessage(), context(), default empty context |
| `tests/Unit/Domain/Shared/Validation/Concerns/ThrowsOnValidationFailureTest.php` | orFail() no-op on pass, orFail() throws on fail with correct reason/context |
| `tests/Unit/Domain/Shared/Validation/Concerns/AggregatesChildResultsTest.php` | all pass → passed(), any fail → failed(), reason() joins with "; ", context() nests under keys, single failure no semicolon, orFail() with aggregated data |

**Tests for Product.allSkus():**

| File | Tests |
|------|-------|
| `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductTest.php` | master only, master + variations, null master, null variation skus, no skus, null variations |

**Tests for validator:**

| File | Tests |
|------|-------|
| `tests/Unit/Domain/Catalog/Product/Validators/SkuBelongsToProductValidatorTest.php` | all owned → passes, some missing → fails with correct missingSkus(), empty required → passes, orFail() throws on failure, orFail() no-op on success |

### Phase 9: Verify everything passes

Run `make lint` + `make test` to confirm full suite green.

### Phase 10: Documentation

**Create:** `.ai/docs/guides/validation.md` (most detail)
- Design philosophy (reference report)
- Actual directory structure (with `App\Domain\Shared\Validation\` namespace)
- Namespace mapping note: report uses `Domain\Shared\Validation\`, codebase uses `App\Domain\Shared\Validation\`
- How to create a single validator (step-by-step)
- How to create an aggregate validator (step-by-step, referencing report §Aggregate)
- Consumption patterns (soft path vs strict path)
- Domain vs Application validators
- Interface naming: `*Interface` suffix per codebase convention
- Planned linting rules (from report §Linting Rules — document for future implementation)

**Create:** `app/Domain/Shared/Validation/CLAUDE.md` (medium detail)
- Purpose: cross-cutting validation infrastructure
- File inventory (what each contract/concern does)
- Naming: `*Interface` suffix per project convention
- Rule: single results use `ThrowsOnValidationFailure`, aggregate results use `AggregatesChildResults`
- Rule: validators live with their domain concept in `Validators/` subdirectories
- Report reference: this directory corresponds to `Domain/Shared/Validation/` in the design report

**Modify:** `app/Domain/CLAUDE.md` (small addition)
- Add short section: validators live in `Validators/` subdirectories, validation infrastructure in `Domain/Shared/Validation/`, see `Shared/Validation/CLAUDE.md` for details

## Files Summary

| # | File | Action |
|---|------|--------|
| 1 | `phparkitect.php` | Modify — add `Validators` to Rule 9 |
| 2 | `app/Domain/Exceptions/ValidationFailedException.php` | Create |
| 3 | `app/Domain/Shared/Validation/Contracts/ValidatorInterface.php` | Create |
| 4 | `app/Domain/Shared/Validation/Contracts/DescribableValidationResultInterface.php` | Create |
| 5 | `app/Domain/Shared/Validation/Concerns/ThrowsOnValidationFailure.php` | Create |
| 6 | `app/Domain/Shared/Validation/Concerns/AggregatesChildResults.php` | Create |
| 7 | `app/Domain/Catalog/Product/ValueObjects/Product.php` | Modify — add `allSkus()` |
| 8 | `app/Domain/Catalog/Product/Validators/SkuBelongsToProductValidator.php` | Create |
| 9 | `app/Domain/Catalog/Product/Validators/SkuBelongsToProductResult.php` | Create |
| 10 | `tests/Unit/Domain/Exceptions/ValidationFailedExceptionTest.php` | Create |
| 11 | `tests/Unit/Domain/Shared/Validation/Concerns/ThrowsOnValidationFailureTest.php` | Create |
| 12 | `tests/Unit/Domain/Shared/Validation/Concerns/AggregatesChildResultsTest.php` | Create |
| 13 | `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductTest.php` | Modify — add allSkus() tests |
| 14 | `tests/Unit/Domain/Catalog/Product/Validators/SkuBelongsToProductValidatorTest.php` | Create |
| 15 | `.ai/docs/guides/validation.md` | Create |
| 16 | `app/Domain/Shared/Validation/CLAUDE.md` | Create |
| 17 | `app/Domain/CLAUDE.md` | Modify — add validation section |

## Verification

1. `make lint` — Pint, PHPStan (max), PHPArkitect, Deptrac all pass
2. `make test` — all tests pass including new validator/trait tests
3. Manual review: `ProductSkuValidator` can be consumed via both soft path and strict path
