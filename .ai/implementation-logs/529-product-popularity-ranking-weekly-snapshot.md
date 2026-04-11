# Implementation Log — Issue #529

## Product Popularity Ranking - Weekly Snapshot Persistence System

### Status: In Progress

---

## Plan Reference
`.ai/plans/2026-04-12_529-product-popularity-ranking-weekly-snapshot.md`

---

## Decisions

- **Timestamps**: `2026_04_12_100000–100003` (after latest catalog migration `2026_04_11_220000`)
- **Repository injection**: `DatabaseGateway` (concrete, not interface) — exposes `connection()` for `affectingStatement()`
- **Pattern match**: `ProductLookupTableProvider` confirmed as precedent for concrete injection + `connection()->select/affectingStatement` pattern
- **Interval columns**: Post-create `ALTER TABLE` to cast string→INTERVAL (Blueprint has no INTERVAL type)
- **`sort_order_difference` dropped from view** per plan — pure derived data, consumers compute inline
- **No `retryUntil()`** on the job — weekly cadence doesn't need a tight window like the 10-min sibling
- **No explicit transaction** — single INSERT...SELECT is atomic in PostgreSQL by default

---

## Files Created

- `database/migrations/2026_04_12_100000_create_catalog_product_popularity_ranking_config_table.php`
- `database/migrations/2026_04_12_100001_create_catalog_product_popularity_ranking_view.php`
- `database/migrations/2026_04_12_100002_create_catalog_product_popularity_snapshots_table.php`
- `database/migrations/2026_04_12_100003_create_catalog_product_popularity_ranking_latest_view.php`
- `app/Application/Contracts/Catalog/ProductPopularityRankingSnapshotRepositoryInterface.php`
- `app/Application/Catalog/UseCases/SnapshotProductPopularityRankingUseCase.php`
- `app/Infrastructure/Catalog/Repositories/ProductPopularityRankingSnapshotRepository.php`
- `app/Infrastructure/Jobs/Catalog/SyncProductPopularityRankingSnapshotJob.php`

## Files Modified

- `app/Providers/CatalogServiceProvider.php` — added binding + provides entry
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — added Carbon import + weekly schedule method

---

## PR Notes

**Title**: `feat(catalog): weekly product popularity ranking snapshot (#529)`

**Body**:
- Adds four DB migrations: versioned config table (v1 seeded), expensive ranking view (from `tmp/product_ranking.sql`), append-only snapshot table, cheap latest view
- Clean Architecture: interface in `Application/Contracts/Catalog/`, use case in `Application/Catalog/UseCases/`, repository + job in `Infrastructure/Catalog/`
- Job runs every Sunday 03:00 Europe/London via `CatalogScheduleServiceProvider`
- Duplicate-date runs fail loudly (composite PK collision → `DuplicateRecordException`)
- Zero-row snapshots throw `DatabaseOperationFailedException` (no active config row)
