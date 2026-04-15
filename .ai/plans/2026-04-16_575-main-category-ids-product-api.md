# Plan: Add `main_category_ids` to ProductView + Category Filter

## Context

Two related features:
1. **Product API**: Add `main_category_ids` to every product response — the set of "main category" IDs the product belongs to (directly or via descendant categories)
2. **Category API**: Add `is_main_category` filter to `GET /api/categories` so the frontend can fetch only main categories

Main categories = categories with the `is_main_category` toggle custom field set to `true` (already exists in ShopWired). A product belongs to a main category if it's in that category **or** any descendant.

**Key data constraint**: `parent_ids` stores only the **immediate parent**, requiring a **recursive CTE** to walk up the tree.

---

## Part A: Product `main_category_ids`

### Approach: Recursive CTE in SQL View

Add `main_category_ids` as a computed JSONB column in `catalog.products_view`. Always present in API responses (`baseFields`).

### SQL Design

Change `WITH` → `WITH RECURSIVE`. Three new CTEs after `pricing`:

```sql
-- Step 1: Find main categories by toggle custom field
main_cats AS (
    SELECT c.external_id
    FROM shopwired.categories c
    WHERE c.custom_fields @> '{"is_main_category": true}'::jsonb
),

-- Step 2: Recursively walk UP the tree to build full ancestor chains
-- parent_ids only has immediate parent, so we recurse
ancestors(cat_id, ancestor_id) AS (
    -- Base: every category is its own ancestor
    SELECT c.external_id, c.external_id
    FROM shopwired.categories c

    UNION ALL

    -- Recursive: find parent of current ancestor
    SELECT a.cat_id, pid.val::int
    FROM ancestors a
    JOIN shopwired.categories c ON c.external_id = a.ancestor_id
    CROSS JOIN LATERAL jsonb_array_elements_text(c.parent_ids) AS pid(val)
),

-- Step 3: Keep only main category ancestors
cat_main_map AS (
    SELECT DISTINCT a.cat_id, a.ancestor_id AS main_cat_id
    FROM ancestors a
    INNER JOIN main_cats mc ON mc.external_id = a.ancestor_id
)
```

New column in SELECT (after `has_free_delivery`):
```sql
COALESCE(
    (SELECT jsonb_agg(sub.main_cat_id)
     FROM (
         SELECT DISTINCT cmm.main_cat_id
         FROM jsonb_array_elements_text(p.category_ids) AS elem(cat_id)
         JOIN cat_main_map cmm ON cmm.cat_id = elem.cat_id::int
         ORDER BY cmm.main_cat_id
     ) sub
    ),
    '[]'::jsonb
) AS main_category_ids
```

---

## Part B: Category `is_main_category` Filter

### Approach: JSONB WHERE clause in repository scope

Add an `isMainCategory` boolean filter to the category list endpoint. When `true`, filter categories where `custom_fields @> '{"is_main_category": true}'::jsonb`. This is a simple WHERE clause addition — no schema changes needed since the data already exists in `custom_fields` JSONB.

---

## Changes (9 files modified/created)

### Part A — Product main_category_ids

#### 1. Migration (NEW): `database/migrations/2026_04_16_000001_add_main_category_ids_to_catalog_products_view.php`
- DROP + CREATE `catalog.products_view`
- Copy full SQL from `2026_04_03_000001_update_catalog_products_view_stock_from_linnworks.php`
- `WITH` → `WITH RECURSIVE`
- Add `main_cats`, `ancestors`, `cat_main_map` CTEs after `pricing`
- Add `main_category_ids` computed column after `has_free_delivery`
- `down()` recreates current view (copy from `2026_04_03_000001` up() verbatim)

#### 2. `ProductViewModel` — `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php`
- Add `@property list<int> $main_category_ids` to docblock
- Add `'main_category_ids' => 'array'` to `jsonCasts()`

#### 3. `ProductView` — `app/Domain/Catalog/Product/ValueObjects/ProductView.php`
- Add property: `/** @var list<IntId> */ public array $mainCategoryIds;`
- Add constructor parameter: `array $mainCategoryIds` (after `$categoryIds`)
- Add constructor body mapping ints → IntId (same pattern as `categoryIds` line 149)
- Add `@param list<int> $mainCategoryIds Main category IDs this product belongs to` to docblock

#### 4. `ProductViewAssembler` — `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- Add `mainCategoryIds: $model->main_category_ids` in `new ProductView(...)` call (after `categoryIds`)

#### 5. `ProductResource` — `app/Presentation/Http/Api/Resources/ProductResource.php`
- Add to `baseFields()`: `'main_category_ids' => \array_map(static fn(IntId $id): int => $id->value, $product->mainCategoryIds),`
- Add `use App\Domain\ValueObjects\IntId;` import

### Part B — Category isMainCategory filter

#### 6. `ListCategoriesRequestDTO` — `app/Presentation/Http/Api/DTOs/ListCategoriesRequestDTO.php`
- Add parameter: `#[Nullable, BooleanType] public readonly ?bool $is_main_category = null`
- Nullable so `null` = no filter, `true` = only main, `false` = only non-main

#### 7. `CategoryController` — `app/Presentation/Http/Api/Controllers/CategoryController.php`
- Pass `isMainCategory: $data->is_main_category` to `ListCategoriesUseCase::execute()`

#### 8. `ListCategoriesUseCase` — `app/Application/Catalog/UseCases/ListCategoriesUseCase.php`
- Add `?bool $isMainCategory = null` parameter to `execute()`
- Pass through to `$this->categoryRepository->paginate()`
- Add to log context

#### 9a. `CategoryRepositoryInterface` — `app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php`
- Add `?bool $isMainCategory = null` parameter to `paginate()` signature

#### 9b. `EloquentCategoryRepository` — `app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php`
- Add `?bool $isMainCategory = null` parameter to `paginate()`
- Add to scope:
  ```php
  if ($isMainCategory !== null) {
      $isMainCategory
          ? $q->whereRaw("custom_fields @> ?::jsonb", ['{"is_main_category": true}'])
          : $q->where(function (Builder $q): void {
              $q->whereRaw("NOT (custom_fields @> ?::jsonb)", ['{"is_main_category": true}'])
                ->orWhereRaw("custom_fields IS NULL");
          });
  }
  ```

---

## Files NOT changed

- `ProductDetailResource` — inherits `main_category_ids` from `baseFields()` automatically
- `ProductInclude` enum — not needed, field is always present

## Verification

### Part A
1. `php artisan migrate` — view recreated with new column
2. `SELECT external_id, main_category_ids FROM catalog.products_view WHERE main_category_ids != '[]' LIMIT 10`
3. Verify category 112670 (child of 64916) resolves correctly through the ancestor chain
4. `GET /api/products` — `main_category_ids` appears in list response
5. `GET /api/products/{id}` — `main_category_ids` appears in detail response
6. Products with no main category ancestry return `[]`

### Part B
7. `GET /api/categories?is_main_category=1` — returns only main categories
8. `GET /api/categories?is_main_category=0` — returns only non-main categories
9. `GET /api/categories` (no filter) — returns all categories as before

### Quality
10. `make lint` + `make test`
