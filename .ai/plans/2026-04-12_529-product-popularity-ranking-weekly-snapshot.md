# Plan: Product Popularity Ranking — Persistence System

## Context

The exploratory SQL query at `tmp/product_ranking.sql` is now well-calibrated (four-sub-score percentile blend, disjoint main/recent windows, sellers-only partition, max_rank=12). The top-50 inspection confirms the B2B/consumer balance works, the disjoint-windows fix resolves the recency double-counting bug, and the tier distribution is healthy.

We now want to **run it weekly and keep every single weekly run as permanent history**, so we can:

1. Track how each product's rank evolves over time (rising/falling bestsellers, seasonal patterns, campaign impact)
2. Compare any two historical snapshots (e.g. "what was the top 20 six months ago?")
3. Tune the algorithm without losing comparability — snapshots are tagged with an `algorithm_version` so a config change doesn't retroactively mutate history
4. Serve "current ranking" reads cheaply — dashboards read the latest snapshot (indexed table scan), not the expensive window-function view (~10–30s on prod)

Design lock-ins from the planning conversation (all confirmed by the user):

- **Four database objects in the `catalog` schema**: config table, expensive ranking view (write path), append-only snapshot table, cheap "latest" view (read path). `catalog` is a cross-cutting read model over shopwired + linnworks — this report belongs there, not in `shopwired`.
- **`algorithm_version` is the PK of the config table** and threads through the view → snapshot rows as an FK, giving full auditability for free
- **Default `pgsql` connection** — `catalog` schema already has `ALTER DEFAULT PRIVILEGES` granting to `service_role`/`authenticated` (see `2026_03_31_110000_create_catalog_schema.php`), so scheduled writes use the standard connection like every other catalog sync job. No bespoke `pgsql_admin` routing.
- **Scheduled job only** — no manual Artisan command. Reruns via tinker if ever needed.
- **Sunday 03:00 Europe/London** via `Schedule::job(...)` added to the existing `CatalogScheduleServiceProvider`
- **Error out on duplicate `snapshot_date`** via composite PK — accidental double-fires fail loudly, no silent overwrites

## Architecture overview

```
┌─────────────────────────────────────────────────────────────┐
│ catalog.product_popularity_ranking_config                   │  Versioned params,
│   PK: algorithm_version  SMALLINT                           │  partial unique on
│   ┌──────────────────────────────────────┐                  │  (is_active=true)
│   │ main_period, recent_period,          │                  │
│   │ w_main, w_recent, w_qty, w_turnover, │                  │
│   │ max_rank, is_active, notes, ...      │                  │
│   └──────────────────────────────────────┘                  │
└─────────────────────────────────────────────────────────────┘
                    │
                    │ CROSS JOIN params (WHERE is_active=true)
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ catalog.product_popularity_ranking            (VIEW)         │  Expensive.
│   Output columns: algorithm_version, parent_external_id,    │  Only read by
│   sku, title, is_active, main_qty, main_turnover,           │  the weekly job.
│   recent_qty, recent_turnover, main/recent_qty/turnover     │
│   _rank, main_score, recent_score, final_score,             │
│   calculated_sort_order, current_sort_order, trend          │
└─────────────────────────────────────────────────────────────┘
                    │
                    │ INSERT INTO snapshots SELECT CURRENT_DATE, v.*
                    │       (weekly, in SyncProductPopularityRankingSnapshotJob)
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ catalog.product_popularity_snapshots          (TABLE)        │  Append-only.
│   PK: (snapshot_date, parent_external_id)                   │  History of every
│   FK: algorithm_version → config                            │  weekly run.
│   + all ranking-view columns                                │
│   Indexes:                                                  │
│     (parent_external_id, snapshot_date DESC)                │
│     (algorithm_version, snapshot_date DESC)                 │
└─────────────────────────────────────────────────────────────┘
                    │
                    │ WHERE snapshot_date = MAX(snapshot_date)
                    │  ORDER BY final_score DESC
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ catalog.product_popularity_ranking_latest     (VIEW)         │  Cheap.
│   All snapshot columns, filtered to the latest snapshot,    │  Read path for
│   pre-sorted by final_score DESC.                           │  all consumers.
└─────────────────────────────────────────────────────────────┘
```

## 1. Database migrations (4 files)

All filenames include `catalog` so `database/CLAUDE.md`'s `DROP SCHEMA CASCADE` reset pattern (`LIKE '%catalog%'`) finds them on re-run. Matches the existing catalog filter-view migrations.

### 1a. `{ts1}_create_catalog_product_popularity_ranking_config_table.php`

```php
Schema::create('catalog.product_popularity_ranking_config', function (Blueprint $table): void {
    $table->smallInteger('algorithm_version')->primary();

    $table->string('main_period_interval');    // stored as PG interval string, e.g. '12 months'
    $table->string('recent_period_interval');  // e.g. '2 months'

    $table->decimal('w_main', 5, 3);
    $table->decimal('w_recent', 5, 3);
    $table->decimal('w_qty', 5, 3);
    $table->decimal('w_turnover', 5, 3);

    $table->smallInteger('max_rank');
    $table->boolean('is_active')->default(false);
    $table->text('notes')->nullable();
    $table->timestampTz('created_at')->useCurrent();
});

// INTERVAL columns can't be expressed via Blueprint — convert after create
DB::statement("ALTER TABLE catalog.product_popularity_ranking_config
    ALTER COLUMN main_period_interval   TYPE INTERVAL USING main_period_interval::interval,
    ALTER COLUMN recent_period_interval TYPE INTERVAL USING recent_period_interval::interval");

// Check constraints replicate the invariants currently living in SQL comments
DB::statement("ALTER TABLE catalog.product_popularity_ranking_config
    ADD CONSTRAINT ck_recent_lt_main   CHECK (recent_period_interval < main_period_interval),
    ADD CONSTRAINT ck_positive_weights CHECK (w_main > 0 AND w_recent > 0 AND w_qty > 0 AND w_turnover > 0),
    ADD CONSTRAINT ck_max_rank_range   CHECK (max_rank BETWEEN 2 AND 100)");

// At most one active row — enforced at the database level
DB::statement("CREATE UNIQUE INDEX idx_popularity_config_single_active
    ON catalog.product_popularity_ranking_config (is_active)
    WHERE is_active = true");

// Seed v1 with the current tuned defaults from tmp/product_ranking.sql
DB::table('catalog.product_popularity_ranking_config')->insert([
    'algorithm_version'     => 1,
    'main_period_interval'  => '12 months',
    'recent_period_interval'=> '2 months',
    'w_main'                => 0.700,
    'w_recent'              => 0.300,
    'w_qty'                 => 0.500,
    'w_turnover'            => 0.500,
    'max_rank'              => 12,
    'is_active'             => true,
    'notes'                 => 'Initial version — disjoint windows, sellers-only percentile, 50/50 qty+turnover blend',
]);
```

Precedent for the "create table, then post-create `ALTER TABLE` for CHECK + partial unique" pattern:
- `database/migrations/2026_01_28_202332_create_operations_sku_changes_table.php` (CHECK)
- `database/migrations/2026_03_19_204850_create_operations_price_periods_table.php` (partial unique)

### 1b. `{ts2}_create_catalog_product_popularity_ranking_view.php`

`DB::statement` with heredoc `<<<'SQL'`, matching the pattern in:
- `database/migrations/2026_03_24_130000_create_shopwired_order_products_resolved_view.php`
- `database/migrations/2026_02_01_100001_create_orders_deduplicated_view_shopwired.php`

The SQL is the current `tmp/product_ranking.sql` with two changes:
1. `params` CTE replaced with `SELECT algorithm_version, main_period_interval AS main_period, recent_period_interval AS recent_period, w_main, w_recent, w_qty, w_turnover, max_rank FROM catalog.product_popularity_ranking_config WHERE is_active = true`
2. `algorithm_version` propagated into the final `SELECT` as the first column (comes from the `params` CROSS JOIN that already threads through every CTE)

```php
public function up(): void
{
    DB::statement(<<<'SQL'
        CREATE VIEW catalog.product_popularity_ranking AS
        WITH
        params AS (
            SELECT
                algorithm_version,
                main_period_interval   AS main_period,
                recent_period_interval AS recent_period,
                w_main, w_recent, w_qty, w_turnover, max_rank
            FROM catalog.product_popularity_ranking_config
            WHERE is_active = true
        ),
        resolved_lines AS ( ... ),    -- unchanged, from tmp/product_ranking.sql
        period_totals  AS ( ... ),    -- unchanged (disjoint windows)
        products_with_totals AS ( ... ),
        ranked AS ( ... ),            -- sellers-only partition, unchanged
        period_scores AS ( ... ),
        final_scores  AS ( ... )
        SELECT
            params.algorithm_version,
            fs.parent_external_id,
            fs.sku,
            fs.title,
            fs.is_active,
            ((params.max_rank + 1) - ROUND(fs.final_score)::int) AS calculated_sort_order,
            fs.current_sort_order,
            fs.main_qty,
            ROUND(fs.main_turnover, 2)           AS main_turnover,
            fs.recent_qty,
            ROUND(fs.recent_turnover, 2)         AS recent_turnover,
            ROUND(fs.main_qty_rank, 2)           AS main_qty_rank,
            ROUND(fs.main_turnover_rank, 2)      AS main_turnover_rank,
            ROUND(fs.recent_qty_rank, 2)         AS recent_qty_rank,
            ROUND(fs.recent_turnover_rank, 2)    AS recent_turnover_rank,
            ROUND(fs.main_score, 2)              AS main_score,
            ROUND(fs.recent_score, 2)            AS recent_score,
            ROUND(fs.final_score, 2)             AS final_score,
            ROUND(fs.recent_score - fs.main_score, 2) AS trend
        FROM final_scores fs
        CROSS JOIN params
        SQL);
}

public function down(): void
{
    DB::statement('DROP VIEW IF EXISTS catalog.product_popularity_ranking');
}
```

**Note on ordering**: the view is NOT ordered inside the definition — we let consumers apply `ORDER BY final_score DESC` themselves to avoid locking in a sort that the snapshot writer doesn't need.

### 1c. `{ts3}_create_catalog_product_popularity_snapshots_table.php`

```php
Schema::create('catalog.product_popularity_snapshots', function (Blueprint $table): void {
    $table->date('snapshot_date');
    $table->smallInteger('algorithm_version');
    $table->integer('parent_external_id');

    $table->string('sku')->nullable();
    $table->text('title')->nullable();
    $table->boolean('is_active')->nullable();

    $table->smallInteger('calculated_sort_order');       // bounded by max_rank (2..100) — smallint is fine
    $table->integer('current_sort_order')->nullable();   // matches shopwired.products.sort_order (integer)
    // NOTE: no `sort_order_difference` column — it's pure derived data
    // (`calculated_sort_order - current_sort_order`). Consumers that need it
    // compute inline via SELECT. Removed from the view too.

    $table->decimal('main_qty', 12, 0);
    $table->decimal('main_turnover', 14, 2);
    $table->decimal('recent_qty', 12, 0);
    $table->decimal('recent_turnover', 14, 2);

    $table->decimal('main_qty_rank', 5, 2);
    $table->decimal('main_turnover_rank', 5, 2);
    $table->decimal('recent_qty_rank', 5, 2);
    $table->decimal('recent_turnover_rank', 5, 2);

    $table->decimal('main_score', 5, 2);
    $table->decimal('recent_score', 5, 2);
    $table->decimal('final_score', 5, 2);
    $table->decimal('trend', 5, 2);                      // range ~-11.00..+11.00 (recent - main)

    $table->timestampsTz();  // created_at / updated_at — populated via DEFAULT NOW() at the DB level

    $table->primary(['snapshot_date', 'parent_external_id']);

    $table->foreign('algorithm_version')
        ->references('algorithm_version')
        ->on('catalog.product_popularity_ranking_config')
        ->restrictOnDelete();

    // Product history over time (e.g. "how has product X's rank moved?")
    $table->index(['parent_external_id', 'snapshot_date'], 'idx_popularity_snapshots_product_history');

    // Filter by algorithm version (e.g. "apples-to-apples compare only v2+ runs")
    $table->index(['algorithm_version', 'snapshot_date'], 'idx_popularity_snapshots_by_version');
});

// Laravel's Blueprint doesn't apply DEFAULT NOW() to timestampsTz(). Add it so the
// INSERT...SELECT doesn't need to populate them explicitly — matches other shopwired tables.
DB::statement("ALTER TABLE catalog.product_popularity_snapshots
    ALTER COLUMN created_at SET DEFAULT NOW(),
    ALTER COLUMN updated_at SET DEFAULT NOW()");
```

The writer uses an **explicit column list** in both the INSERT targets and the SELECT projection (see §3 Repository) rather than `SELECT v.*`, so column order in `Schema::create` is not load-bearing — a view column reorder cannot silently misalign with the table.

### 1d. `{ts4}_create_catalog_product_popularity_ranking_latest_view.php`

```php
public function up(): void
{
    DB::statement(<<<'SQL'
        CREATE VIEW catalog.product_popularity_ranking_latest AS
        SELECT *
        FROM catalog.product_popularity_snapshots
        WHERE snapshot_date = (SELECT MAX(snapshot_date) FROM catalog.product_popularity_snapshots)
        ORDER BY final_score DESC
        SQL);
}

public function down(): void
{
    DB::statement('DROP VIEW IF EXISTS catalog.product_popularity_ranking_latest');
}
```

Fast because the `MAX(snapshot_date)` subquery is an index-only scan on the primary key, and the outer query becomes a PK range scan on all rows with that date.

## 2. Application layer

### Interface — `app/Application/Contracts/Catalog/ProductPopularityRankingSnapshotRepositoryInterface.php`

```php
interface ProductPopularityRankingSnapshotRepositoryInterface
{
    /**
     * Writes one row per product into `catalog.product_popularity_snapshots`
     * by doing `INSERT ... SELECT CURRENT_DATE, v.* FROM catalog.product_popularity_ranking v`.
     *
     * @return int Number of rows written (one per product in the catalog)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException          If a snapshot for `CURRENT_DATE` already exists
     * @throws ExternalServiceUnavailableException
     */
    public function writeSnapshotForToday(): int;
}
```

Three `@throws` are load-bearing — `DatabaseGateway::query()` translates `QueryException` / `PDOException` / `UniqueConstraintViolationException` into exactly these three types, and `app/Application/CLAUDE.md` requires the interface to enumerate them because PHPStan can't infer the chain.

### Use case — `app/Application/Catalog/UseCases/SnapshotProductPopularityRankingUseCase.php`

```php
final readonly class SnapshotProductPopularityRankingUseCase
{
    public function __construct(
        private ProductPopularityRankingSnapshotRepositoryInterface $snapshotRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    /**
     * @throws DatabaseOperationFailedException when the snapshot writes zero rows
     *                                          (indicates no active config row — fail-loud)
     */
    public function execute(): void
    {
        $this->logger->info('SnapshotProductPopularityRanking: starting');

        $rowsWritten = $this->snapshotRepository->writeSnapshotForToday();

        if ($rowsWritten === 0) {
            // Zero rows = no active config row (view's WHERE is_active = true returned nothing).
            // Treat as a hard failure — silent zero-row snapshots would go undetected for weeks.
            throw new DatabaseOperationFailedException(
                operation: 'product_popularity_ranking_snapshot',
                reason: 'no_active_config_row',
            );
        }

        $this->logger->info('SnapshotProductPopularityRanking: completed', [
            'rows_written' => $rowsWritten,
        ]);
    }
}
```

No try/catch — per `app/Application/CLAUDE.md` ("Application is a thin coordinator; don't catch by default"), exceptions propagate to the job, which relies on Laravel's `HandleDatabaseExceptions` middleware + Sentry integration in `bootstrap/app.php` to handle retry/failure. The explicit `rowsWritten === 0` check is the only in-use-case logic — it converts a fail-silent mode (zero active configs) into a fail-loud exception that hits Sentry on the very first bad run.

**Verify the `DatabaseOperationFailedException` constructor signature** before using — if it doesn't accept `operation` / `reason` kwargs, fall back to whatever constructor shape it already uses. Static message only (per `app/Infrastructure/CLAUDE.md`).

Canonical precedent: `app/Application/Catalog/UseCases/SyncShippingOptionsFiltersUseCase.php`.

## 3. Infrastructure layer

### Repository — `app/Infrastructure/Catalog/Repositories/ProductPopularityRankingSnapshotRepository.php`

```php
/**
 * Writes one snapshot row per product into `catalog.product_popularity_snapshots`.
 *
 * Uses the default `pgsql` connection via DatabaseGateway — the `catalog` schema has
 * default privileges granting INSERT to `service_role` (see the catalog schema migration),
 * so no special connection routing is needed. Follows the same pattern as the other
 * catalog filter-sync repositories.
 *
 * NOTE: Intentionally does NOT extend AbstractEloquentRepository. There is no domain entity
 * to persist — the write is a single raw INSERT...SELECT from a database view. Precedent:
 * app/Infrastructure/Mixpanel/LookupTables/ProductLookupTableProvider.php.
 */
final readonly class ProductPopularityRankingSnapshotRepository
    implements ProductPopularityRankingSnapshotRepositoryInterface
{
    public function __construct(
        private DatabaseGatewayInterface $databaseGateway,
    ) {}

    public function writeSnapshotForToday(): int
    {
        // DatabaseGateway::query() wraps the closure in its QueryException → Domain exception
        // translation try/catch (DuplicateRecordException / DatabaseOperationFailedException /
        // ExternalServiceUnavailableException). No explicit transaction needed — Postgres
        // auto-commits the single INSERT...SELECT atomically.
        return $this->databaseGateway->query(
            fn(): int => DB::affectingStatement(self::buildInsertSql()),
        );
    }

    /**
     * Explicit column list (not SELECT v.*) so a column reorder in the view doesn't silently
     * misalign with the snapshot table.
     */
    private static function buildInsertSql(): string
    {
        return <<<'SQL'
            INSERT INTO catalog.product_popularity_snapshots (
                snapshot_date,
                algorithm_version,
                parent_external_id,
                sku,
                title,
                is_active,
                calculated_sort_order,
                current_sort_order,
                main_qty,
                main_turnover,
                recent_qty,
                recent_turnover,
                main_qty_rank,
                main_turnover_rank,
                recent_qty_rank,
                recent_turnover_rank,
                main_score,
                recent_score,
                final_score,
                trend
            )
            SELECT
                CURRENT_DATE AS snapshot_date,
                v.algorithm_version,
                v.parent_external_id,
                v.sku,
                v.title,
                v.is_active,
                v.calculated_sort_order,
                v.current_sort_order,
                v.main_qty,
                v.main_turnover,
                v.recent_qty,
                v.recent_turnover,
                v.main_qty_rank,
                v.main_turnover_rank,
                v.recent_qty_rank,
                v.recent_turnover_rank,
                v.main_score,
                v.recent_score,
                v.final_score,
                v.trend
            FROM catalog.product_popularity_ranking v
            SQL;
    }
}
```

**Verify at implementation time**: per the project's `CLAUDE.md` rule "Use DatabaseGateway, never DB:: facade", confirm the canonical pattern for "execute a raw INSERT inside `databaseGateway->query()`" used by the other catalog sync repositories — they may expose a helper that avoids naming `DB::` directly. Mirror that pattern exactly. The important thing is that the exception-translation wrapper is still in play; the facade-vs-helper detail is a copy-the-precedent decision.

### Job — `app/Infrastructure/Jobs/Catalog/SyncProductPopularityRankingSnapshotJob.php`

```php
final class SyncProductPopularityRankingSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 3600;          // Low queue tier per app/Infrastructure/Jobs/CLAUDE.md
    public bool $failOnTimeout = true;
    public int $uniqueFor = 21600;       // 6 hours — blocks double-fires within the same run window
    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'sync-product-popularity-ranking-snapshot';
    }

    public function middleware(): array
    {
        return [new HandleDatabaseExceptions()];
    }

    public function handle(SnapshotProductPopularityRankingUseCase $useCase): void
    {
        $useCase->execute();
    }
}
```

Naming uses `Sync` prefix — required by `app/Infrastructure/Jobs/CLAUDE.md` (allowed prefixes: `Sync|Process|Reconcile|Set|Update|Cleanup`, enforced by custom PHPStan rules in `DevTools/PHPStan/Rules/Jobs/`). `Snapshot` is not in the allowed list.

Precedent: `app/Infrastructure/Jobs/Catalog/SyncShippingOptionsFiltersJob.php` (thin, use case method-injected into handle).

## 4. Provider wiring

### Binding — `app/Providers/CatalogServiceProvider.php` (**existing file — add binding**)

```php
$this->app->bind(
    ProductPopularityRankingSnapshotRepositoryInterface::class,
    ProductPopularityRankingSnapshotRepository::class,
);
```

This is the correct provider for catalog-domain infrastructure — the existing catalog filter-sync repositories are all bound here.

### Schedule registration — `app/Providers/Schedule/CatalogScheduleServiceProvider.php` (**existing file — add method**)

`CatalogScheduleServiceProvider` already exists (currently registers 5 catalog filter syncs) and is already registered in `bootstrap/providers.php`. We **add** a new method and call it from the existing `boot()`.

1. Add imports at the top of the file (Carbon is NOT currently imported — the existing methods use `cron()`, not `weeklyOn()`):
   ```php
   use App\Infrastructure\Jobs\Catalog\SyncProductPopularityRankingSnapshotJob;
   use Carbon\Carbon;
   ```

2. Add one line inside the existing `boot()` (at the end of the existing registration list):
   ```php
   $this->registerProductPopularityRankingSnapshotSchedule();
   ```

3. Append the new registration method to the class:
   ```php
   /**
    * Weekly product popularity ranking snapshot.
    *
    * Runs Sunday 03:00 Europe/London — during the quietest traffic period,
    * capturing a snapshot of the `catalog.product_popularity_ranking` view.
    * Each run inserts ~2,500 rows (one per catalog product) tagged with
    * `algorithm_version` from the active config row.
    */
   private function registerProductPopularityRankingSnapshotSchedule(): void
   {
       Schedule::job(new SyncProductPopularityRankingSnapshotJob())
           ->name('sync-product-popularity-ranking-snapshot')
           ->weeklyOn(Carbon::SUNDAY, '03:00')
           ->timezone('Europe/London')
           ->onOneServer()
           ->withoutOverlapping(60);
   }
   ```

No new provider to register in `bootstrap/providers.php`.

## Critical files (existing, to reference or modify)

### Modify

- `tmp/product_ranking.sql` — source for the view SQL (not checked into the repo). **Also drop the `sort_order_difference` column from the final SELECT** when lifting it into the view migration — it's pure derived data and no longer stored in the snapshot table.
- `app/Providers/CatalogServiceProvider.php` — existing file, add the interface→implementation binding
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — existing file, add `Carbon` import + `registerProductPopularityRankingSnapshotSchedule()` method and call it from `boot()`

### Create

- `database/migrations/{ts1}_create_catalog_product_popularity_ranking_config_table.php`
- `database/migrations/{ts2}_create_catalog_product_popularity_ranking_view.php`
- `database/migrations/{ts3}_create_catalog_product_popularity_snapshots_table.php`
- `database/migrations/{ts4}_create_catalog_product_popularity_ranking_latest_view.php`
- `app/Application/Contracts/Catalog/ProductPopularityRankingSnapshotRepositoryInterface.php`
- `app/Application/Catalog/UseCases/SnapshotProductPopularityRankingUseCase.php`
- `app/Infrastructure/Catalog/Repositories/ProductPopularityRankingSnapshotRepository.php`
- `app/Infrastructure/Jobs/Catalog/SyncProductPopularityRankingSnapshotJob.php`

### Reference (do not modify — patterns to copy)

- `app/Providers/RlsDatabaseServiceProvider.php` — confirms `pgsql_admin` is the correct connection for scheduled admin writes
- `app/Infrastructure/Database/DatabaseGateway.php` — `query()` is a pure try/catch wrapper; safe to pass a closure that uses any connection
- `app/Infrastructure/Catalog/Repositories/ShippingOptionsFilterQueryRepository.php` — read-only view-query precedent
- `app/Infrastructure/Mixpanel/LookupTables/ProductLookupTableProvider.php` — non-Eloquent infrastructure class precedent (doesn't extend `AbstractEloquentRepository`)
- `app/Infrastructure/Linnworks/Repositories/EloquentPurchaseOrderSyncRepository.php` — transaction usage precedent (though we don't need an explicit transaction)
- `app/Application/Catalog/UseCases/SyncShippingOptionsFiltersUseCase.php` — use-case shape + PSR-3 logger injection + "don't catch" convention
- `app/Infrastructure/Jobs/Catalog/SyncShippingOptionsFiltersJob.php` — thin job shape + ShouldBeUnique + method-injected use case
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — `Schedule::job(...)` registration pattern
- `database/migrations/2026_03_19_204850_create_operations_price_periods_table.php` — partial unique index precedent
- `database/migrations/2026_01_28_202332_create_operations_sku_changes_table.php` — CHECK constraint + partial index precedents in one migration
- `database/migrations/2026_03_24_130000_create_shopwired_order_products_resolved_view.php` — `CREATE VIEW` via `DB::statement` heredoc precedent
- `database/CLAUDE.md` — schema-qualified migration filename rule (`%shopwired%` pattern for resets)
- `app/Application/CLAUDE.md` — interface placement, `@throws` enumeration, "don't catch" convention
- `app/Infrastructure/CLAUDE.md` — repository base class rule (explicitly broken here with rationale)
- `app/Infrastructure/Jobs/CLAUDE.md` — queue tier table (low = 3600s timeout), naming prefixes, ShouldBeUnique

## Deviation notes (worth flagging at code review)

1. **Repository doesn't extend `AbstractEloquentRepository`.** `app/Infrastructure/CLAUDE.md` says all new repositories MUST. The rule is written with entity-persistence in mind, and our class is a single-statement raw-SQL writer with no entity. Precedent for the deviation: `ProductLookupTableProvider` implements its interface directly. Docblock in the new class explains the rationale.

2. **INTERVAL columns on the config table require a post-create `ALTER TABLE`** because Laravel's Blueprint has no INTERVAL type. Same pattern used elsewhere for PG-specific types.

3. **Existing providers are modified, not created.** Both `CatalogServiceProvider` and `CatalogScheduleServiceProvider` already exist and are registered in `bootstrap/providers.php`. We add bindings/methods to them, not new files. Grants on new tables and views are automatic thanks to `ALTER DEFAULT PRIVILEGES IN SCHEMA catalog` set in `2026_03_31_110000_create_catalog_schema.php` — nothing to add there.

## Verification

### Local smoke test (before merge)

1. **Run the migrations**:
   ```bash
   php artisan migrate
   ```
   Confirms all 4 objects created, check constraints + partial unique index applied, seed row for `algorithm_version = 1` present and active.

2. **Verify the view compiles and returns rows**:
   ```sql
   SELECT COUNT(*), MIN(final_score), MAX(final_score)
   FROM catalog.product_popularity_ranking;
   ```
   Expect ~2,500 rows (matches current catalog size) and a final_score range of roughly 1.00–11.90.

3. **Dispatch the job locally** (per `CLAUDE.md` — dispatch via tinker, never Railway):
   ```bash
   php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncProductPopularityRankingSnapshotJob::dispatchSync();"
   ```
   Check `storage/logs/laravel.log` for:
   - `SnapshotProductPopularityRanking: starting`
   - `SnapshotProductPopularityRanking: completed` with `rows_written` ~= 2,500

4. **Verify the snapshot landed**:
   ```sql
   SELECT snapshot_date, algorithm_version, COUNT(*)
   FROM catalog.product_popularity_snapshots
   GROUP BY snapshot_date, algorithm_version;
   ```
   Expect one row: today's date, version 1, ~2,500 rows.

5. **Verify the latest view**:
   ```sql
   SELECT * FROM catalog.product_popularity_ranking_latest ORDER BY final_score DESC LIMIT 10;
   ```
   Should match the top 10 from the original `tmp/product_ranking.sql` inspection run.

6. **Verify duplicate-run behavior** (dispatch the job a second time):
   ```bash
   php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncProductPopularityRankingSnapshotJob::dispatchSync();"
   ```
   Expect `DuplicateRecordException` (primary-key collision on `(snapshot_date, parent_external_id)`) → job marked failed. This confirms the "error out loudly" behavior is working.

7. **Verify FK integrity**:
   ```sql
   BEGIN;
   DELETE FROM catalog.product_popularity_ranking_config WHERE algorithm_version = 1;
   ROLLBACK;
   ```
   Expect a foreign-key violation — snapshots reference the config row, so it can't be deleted while history exists.

### Lint

```bash
make lint        # Pint + PHPStan + PHPArkitect + Deptrac + TLint
```

Watch for:
- PHPArkitect: repository naming, layer dependencies, job naming prefix (`Sync*`)
- PHPStan: `@throws` enumeration on the interface matches what `DatabaseGateway` can actually throw; custom job rules in `DevTools/PHPStan/Rules/Jobs/`
- Deptrac: Application → Infrastructure is one-way (interface in Application, impl in Infrastructure — clean)

### Tests (deferred)

**No tests in this PR.** Rationale: the feature is integration-heavy (four DB objects + a complex view + an INSERT...SELECT), and unit-testing the use case in isolation provides little value beyond restating the happy-path logic. The existing catalog sync repositories ship without unit tests for the same reason.

If a unit test is later desired, the natural target is `SnapshotProductPopularityRankingUseCase` — mock the repository, assert it throws `DatabaseOperationFailedException` when `writeSnapshotForToday()` returns 0, and assert the success-path logger calls. One test file, ~3 cases.

### Production verification (after deploy)

1. **Check Horizon** shows the schedule registered (`snapshot-product-popularity-ranking` job listed under recent schedules).
2. **First Sunday after deploy at 03:05** — check `storage/logs/octane.log` + Sentry for any failures.
3. **Query the latest view** on Monday morning:
   ```sql
   SELECT snapshot_date, COUNT(*) FROM catalog.product_popularity_ranking_latest;
   ```
   Confirm a fresh snapshot landed.

### Nice-to-have (post-merge, not in this PR)

- A Grafana / metabase dashboard on `catalog.product_popularity_ranking_latest` with `ORDER BY final_score DESC`.
- An "algorithm tuning" workflow doc: how to bump `algorithm_version` by inserting a new config row (partial unique index auto-demotes the old one).
- Eventual write-back of `calculated_sort_order` to ShopWired via the existing product sync pipeline — separate feature, tracked separately.
