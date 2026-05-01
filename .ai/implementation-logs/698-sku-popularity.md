# Implementation Log: #698 — Per-SKU Popularity Ranking

**Branch**: `feature/698-add-sku-popularity-ranking`
**Plan**: `.ai/plans/2026-05-01_698-sku-popularity.md`
**Started**: 2026-05-01

## Decision Log

- D1: All stages (0–5) shipped in a single PR per user preference (overrides plan's "one PR per stage")
- D2: Reusing `Popularity` VO unchanged — `fromRank(?int, ?int)` handles nullable case
- D3: `ProductVariationResource::buildData()` decomposed into `identityAndPricingFields()` + `baseFields()` + `conditionalIncludes()` to stay under 20-line PHPStan limit — removed stale baseline entry
- D4: `CatalogServiceProvider::registerRepositories()` decomposed — extracted `registerPopularitySnapshotRepositories()` for product + SKU snapshot bindings

## Implementation Progress

### Stage 0 — catalog.sku_aliases view
- [x] Migration created (`2026_05_01_100000`)

### Stage 1 — sku_popularity_ranking_config table
- [x] Migration created with v1 seed (`2026_05_01_100001`)

### Stage 2 — sku_popularity_ranking view
- [x] Migration created — mirrors product pipeline with SKU canonicalization via sku_aliases (`2026_05_01_100002`)

### Stage 3 — Snapshots table + latest view
- [x] sku_popularity_snapshots table migration (`2026_05_01_100003`)
- [x] sku_popularity_ranking_latest view migration (`2026_05_01_100004`)

### Stage 4 — Snapshot job + scheduling
- [x] SkuPopularityRankingSnapshotRepositoryInterface
- [x] SnapshotSkuPopularityRankingUseCase
- [x] SkuPopularityRankingSnapshotRepository
- [x] SyncSkuPopularityRankingSnapshotJob
- [x] Schedule registration in CatalogScheduleServiceProvider
- [x] Binding in CatalogServiceProvider

### Stage 5 — Read-path wiring
- [x] Extend catalog.product_variations_view with popularity columns (`2026_05_01_100005`)
- [x] ProductVariationViewModel — add property annotations + casts
- [x] ProductVariationView — add ?Popularity property
- [x] ProductVariationModelMapper — build Popularity::fromRank()
- [x] ProductVariationResource — serialize popularity

## Lint / Test

- [x] All 3332 tests pass (12 pre-existing notices, unrelated)
- [x] Pint — passes (auto-fixed import ordering in 3 files)
- [x] PHPStan — passes (decomposed two methods to stay under limit, removed stale baseline)
- [x] PHPArkitect — passes
- [x] Deptrac — passes
- [x] TLint — passes

## Step 9 — Live API validation

- Dispatched `SyncSkuPopularityRankingSnapshotJob` synchronously → 3601 rows written to `catalog.sku_popularity_snapshots` and surfaced via `catalog.sku_popularity_ranking_latest`.
- `GET /api/products/5000559` (variation product) on port 8001 returns `popularity: {rank: 12, max: 12, level: 1}` on each variation node.
- Postmortem during validation: agent hit port 8000 in this worktree (which serves 8001) — fixed by adding `API_PORT` to `.claude/settings.local.json` env block and updating `CLAUDE.md` "Local API Testing" to use `${API_PORT:-8000}`.

## PR Notes

### What
Per-SKU popularity ranking exposed on `ProductVariationView` and serialized through `ProductVariationResource` as `{rank, max, level}` (or `null`). Adds the SQL pipeline + weekly snapshot job mirroring the existing product-level pipeline, and ships `catalog.sku_aliases` as a general-purpose canonicalisation primitive in the same PR per user direction.

### Why
Variations need their own popularity rank rather than inheriting the parent product roll-up. Required to drive variation-level merchandising and consumer-API sort hints.

### Key Decisions
- Single PR for all six stages (sku_aliases + config + ranking view + snapshots + latest view + read-path) — user override of the staged-PR plan
- `Popularity` VO reused unchanged via `Popularity::fromRank(?int, ?int)`
- `ProductVariationResource::buildData()` and `CatalogServiceProvider::registerRepositories()` decomposed to fit PHPStan 20-line limit (no new baseline entries — per project rule)
- No ShopWired write-back: variations have no `sort_order` field
- `sku_aliases` recursive CTE uses UNION (not UNION ALL) for implicit cycle protection; base-case columns cast to `varchar` (unbounded) to bridge `varchar(100)` product/variation SKUs against `varchar(255)` `sku_changes` columns
- Snapshot PK `(snapshot_date, live_sku)` — composite, no separate `snapshot_date` index needed
- `SnapshotSkuPopularityRankingUseCase` treats zero-row writes as a hard failure (mirrors product pipeline) so a bad config row is caught the first run, not weeks later

### Testing
- Full test suite: 3332 tests green
- All five linters green (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- Sweep skill: zero issues
- Live API: snapshot job writes 3601 rows; variation API returns the `popularity` block on `/api/products/{id}` variations
