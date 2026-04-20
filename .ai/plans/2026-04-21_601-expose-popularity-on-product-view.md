# Expose Popularity on ProductView

## Context

The admin product grid needs a "popularity" column rendered as signal-style bars (5 buckets). Popularity rank is produced weekly by `SnapshotProductPopularityRankingUseCase` into `catalog.product_popularity_snapshots`, with `catalog.product_popularity_ranking_latest` as the read view. That raw rank is separately synced daily into `shopwired.products.sort_order` via `SyncProductSortOrdersUseCase` so ShopWired renders the site in popularity order.

**`sort_order` and popularity are not the same thing going forward.** `sort_order` is a ShopWired write channel — today it's popularity, but the plan is to layer manual adjustments onto it (boosts for sale/featured products). Popularity is the raw algorithm score. Reading popularity from `sort_order` would silently drift as soon as any boost logic lands. Popularity must be sourced from the snapshot pipeline directly.

Two gaps for the frontend:

1. **No `max` on the wire.** The bar component needs to know the scale to compute fill buckets (currently `max_rank = 12`, CHECK constrained 2–100, DB-config). Hardcoding on the frontend silently breaks if a new active config row with a different `max_rank` is installed.
2. **Direction + naming leak.** The rank is "lower is better"; any consumer computing a fill level has to repeat the `max − rank + 1` inversion. That math belongs in a domain VO.

The change introduces a `Popularity` value object sourced from the snapshot pipeline, and exposes it on the wire as `popularity: { rank, max } | null` **alongside** the existing `sort_order` field (which stays unchanged).

## Decisions (confirmed with user)

- Wire shape: **nested `popularity: { rank, max } | null`** per product, **alongside** the existing flat `sort_order`.
- Domain: **introduce `Popularity` VO**; `ProductView` gains `public ?Popularity $popularity` while keeping `public ?int $sortOrder`.
- Source: **`catalog.product_popularity_ranking_latest`**, not `shopwired.products.sort_order`. The snapshot's `algorithm_version` joins to the config row for the matching `max_rank`, preserving the rank≤max invariant across config changes.

## Target Wire Shape

```json
{
  "id": 1234,
  "title": "...",
  "sort_order": 3,
  "popularity": { "rank": 3, "max": 12 },
  ...
}
```

`popularity` is `null` for products with no snapshot yet (new products added after the last weekly run, or before the first ever snapshot).

## Files & Changes

### 1. New — `Popularity` value object
`app/Domain/Catalog/Product/ValueObjects/Popularity.php`

```php
final readonly class Popularity
{
    public function __construct(
        public int $rank,  // 1 = most popular; $max = least popular seller / non-seller floor
        public int $max,
    ) {
        Assert::greaterThanEq($rank, 1);
        Assert::lessThanEq($rank, $max);
        Assert::range($max, 2, 100);  // mirrors DB CHECK on config.max_rank
    }

    public static function fromRank(?int $rank, ?int $max): ?self
    {
        return $rank === null || $max === null ? null : new self($rank, $max);
    }

    /**
     * Fill level 1..$segments for bar-style visuals.
     * Inverts "lower rank = more popular" so higher strength = more fill.
     *
     * Note: when $segments > $max, some buckets become unreachable
     * (e.g. max=2, segments=5 never yields bucket 1 or 2). Intrinsic to
     * compressing fewer rank positions into more visual segments.
     */
    public function bucket(int $segments = 5): int
    {
        Assert::greaterThanEq($segments, 1);
        $strength = $this->max - $this->rank + 1;

        return (int) \ceil($strength / $this->max * $segments);
    }
}
```

### 2. Modify — `ProductView`
`app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- **Keep** `public ?int $sortOrder` (line 101) — still reflects the ShopWired write channel.
- **Append** `public ?Popularity $popularity = null` at the **end** of the constructor parameter list, **after** `mainCategoryIds` (currently the last param at line 117). Default to `null`. Appending avoids shifting positional indices of any existing param. Verified safe: the only in-repo `new ProductView(...)` callsites (assembler + `ProductViewTest::createView`) both use named arguments. Popularity arrives pre-built from the assembler — same pattern as `SaleSettings` / `ProductStock`.

### 3. Modify — `ProductViewAssembler`
`app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

Add alongside `sortOrder: $model->sort_order,` (line 98):

```php
popularity: Popularity::fromRank($model->popularity_rank, $model->popularity_max),
```

Add the `Popularity` import.

### 4. Modify — `ProductViewModel`
`app/Infrastructure/Catalog/Product/Models/ProductViewModel.php`

Add docblock properties:

```php
 * @property int|null $popularity_rank Latest snapshot's calculated_sort_order (1 = most popular)
 * @property int|null $popularity_max Max_rank from the config active at snapshot time
```

Add to `numericCasts()`:

```php
'popularity_rank' => 'integer',
'popularity_max' => 'integer',
```

### 5. New migration — extend `catalog.products_view`
`database/migrations/{timestamp}_add_popularity_to_catalog_products_view.php`

Uses `CREATE OR REPLACE VIEW` — Postgres allows appending columns at the end without redefining existing columns. The view gains **two** LEFT JOINs: one to `catalog.product_popularity_ranking_latest` for the rank, one to `catalog.product_popularity_ranking_config` (via `algorithm_version`) for the matching max. Both null together when no snapshot row exists — preserves invariant (rank ≤ max).

Pseudocode of the added bits (full SQL in migration):

```sql
CREATE OR REPLACE VIEW catalog.products_view AS
WITH tax_config AS (...),         -- unchanged
     pricing AS (...)              -- unchanged
SELECT
    ...                            -- all existing columns, unchanged
    prl.calculated_sort_order AS popularity_rank,  -- NEW
    ppc.max_rank              AS popularity_max    -- NEW
FROM shopwired.products p
INNER JOIN pricing pr ON ...       -- unchanged
LEFT JOIN linnworks.stock_items si ON ...    -- unchanged
LEFT JOIN linnworks.stock_item_suppliers s ON ...  -- unchanged
LEFT JOIN catalog.product_popularity_ranking_latest prl  -- NEW
    ON prl.parent_external_id = p.external_id
LEFT JOIN catalog.product_popularity_ranking_config ppc  -- NEW
    ON ppc.algorithm_version = prl.algorithm_version
```

**Why join to config via `algorithm_version`, not `is_active = true`**: stale snapshots produced under an earlier `max_rank` would violate the Popularity VO's `rank ≤ max` assertion if we paired their rank with the currently-active max. Joining via `algorithm_version` pins each row's max to the exact config that produced the rank.

`down()` must **`DROP VIEW IF EXISTS catalog.products_view CASCADE`** first, then `CREATE VIEW ... AS` with the prior (pre-popularity) SELECT from `2026_03_31_110001_create_catalog_product_views.php`. Postgres `CREATE OR REPLACE VIEW` can only **append** columns — it cannot drop or reorder them — so it is unusable for the rollback direction. `CASCADE` is defensive: nothing currently depends on this view, but future downstream views/materialized views would otherwise block the rollback.

**Schema-name filename rule** (per `database/CLAUDE.md`): filename contains `catalog` so schema resets pick it up.

### 6. Modify — `ProductResource`
`app/Presentation/Http/Api/Resources/ProductResource.php`

**Keep** line 85 (`'sort_order' => $product->sortOrder`). **Add** alongside it:

```php
'popularity' => $product->popularity === null ? null : [
    'rank' => $product->popularity->rank,
    'max' => $product->popularity->max,
],
```

### 7. Tests

- **New**: `tests/Unit/Domain/Catalog/Product/ValueObjects/PopularityTest.php`
  - Invariant throws: `rank < 1`, `rank > max`, `max < 2`, `max > 100`
  - `fromRank(null, 12)` → `null`; `fromRank(3, null)` → `null`; `fromRank(3, 12)` → VO
  - `bucket()` table: `(1, 12, 5) → 5`; `(12, 12, 5) → 1`; `(6, 12, 5) → 3`; `(1, 12, 1) → 1`
- **New**: `ProductResource` assertion test that `popularity` appears as nested object OR `null`, with `sort_order` still present as a separate field.
- **Augment**: `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php:755` currently asserts a fixed `$expectedKeys` list including `sort_order`. Add `'popularity'` to that list and add a spot-check that validates the nested shape (`rank` + `max`) when the mocked product carries a non-null `Popularity`.
- **Existing `sort_order` assertions**: verified via grep — no existing test asserts against `sort_order` in a way that conflicts with the plan (it stays in the response unchanged).
- **Snapshot fixture warning**: `catalog.product_popularity_ranking_latest` returns zero rows when `catalog.product_popularity_snapshots` is empty. In a freshly-migrated test DB, **every** product's `popularity` will be `null` unless a test seeds a snapshot row. Most feature tests mock the use case and bypass the view entirely, so this only matters for end-to-end/integration tests that hit the real view — those must seed at least one `product_popularity_snapshots` row for the product under test.

## Reused Existing Utilities

- `Webmozart\Assert\Assert` — project-standard invariant tool (throughout `ProductView`).
- `Popularity::fromRank()` mirrors `Money::nonZeroOrNull()` at `ProductView::__construct()` line 125.
- `catalog.product_popularity_ranking_latest` — existing cheap read-path view (`database/migrations/2026_04_12_100003_*`).
- `CREATE OR REPLACE VIEW` with LEFT JOINs — consistent with how `catalog.products_view` already composes `shopwired.products` + Linnworks + tax config.

## Verification

1. **DB view**: after `php artisan migrate`, run `php artisan tinker`:
   ```php
   DB::table('catalog.products_view')
     ->select('external_id','sort_order','popularity_rank','popularity_max')
     ->limit(5)->get();
   ```
   Expect `popularity_max = 12` wherever `popularity_rank` is non-null; both null together when no snapshot row exists.
2. **Invariant check**: query any product where `popularity_rank > popularity_max` — should return zero rows.
3. **VO unit tests**: `make test-quick`.
4. **Full suite**: `make test`.
5. **End-to-end API**:
   ```bash
   curl -H "X-Local-Bypass: $API_BYPASS_SECRET" 'http://127.0.0.1:8000/api/products?per_page=3'
   ```
   Assert each product has **both** `sort_order` (existing) and `popularity: { rank, max } | null` (new).
6. **Manual spot check**: `jq '.data[] | {sort_order, popularity}'` — confirm the two fields can diverge (will look identical today since sync writes rank → sort_order 1:1, but the wiring is ready for when boost logic lands).

## Out of Scope

- Frontend rendering (AG Grid cellRenderer, SVG bars) — separate task.
- Changing the popularity algorithm, `max_rank` value, or snapshot cadence.
- Introducing the boost logic (sale/featured adjustments that will make `sort_order` diverge from `popularity.rank`).
- Exposing `popularity.bucket()` output on the wire — the frontend computes the fill level itself from `rank`+`max`. The `bucket()` method is domain-facing for future PHP consumers (email templates, PDF exports).
