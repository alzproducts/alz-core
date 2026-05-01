<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.sku_aliases view.
 *
 * General-purpose SKU canonicalisation primitive: maps every historical SKU
 * string to its current "live" canonical form via a recursive walk of
 * operations.sku_changes. Self-pairs are included (every live_sku maps to
 * itself) so consumers get uniform JOIN semantics without a LEFT JOIN + COALESCE.
 *
 * The recursive CTE uses UNION (not UNION ALL) for implicit cycle protection —
 * a rename chain A→B→A terminates naturally because the duplicate (A, A) row
 * is deduplicated by the set union.
 *
 * Seed set: all non-null SKUs from product_variations and products.
 * Walk direction: from current SKU backwards through old_sku→new_sku renames.
 *
 * The outer DISTINCT ON guarantees one row per map_sku — a contract every
 * consumer relies on. Without it, a recycled historical SKU (rename completed
 * for product A, then a new product reuses A and is also renamed) would map
 * to multiple live_sku rows and silently inflate any aggregate JOINing on
 * sa.map_sku = some_sku. Tie-breaker prefers the self-mapping (live_sku =
 * map_sku) so a SKU still in use today wins over its historical aliases.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.sku_aliases AS
            WITH RECURSIVE chain(live_sku, map_sku) AS (
                -- Base case 1: every variation SKU is its own alias
                -- Cast to varchar (unbounded) because sku_changes columns are varchar(255)
                -- while product/variation SKUs are varchar(100).
                SELECT v.sku::varchar, v.sku::varchar
                FROM shopwired.product_variations v
                WHERE v.sku IS NOT NULL

                UNION

                -- Base case 2: every product SKU (non-varying products)
                SELECT p.sku::varchar, p.sku::varchar
                FROM shopwired.products p
                WHERE p.sku IS NOT NULL

                UNION

                -- Recursive step: walk backwards through completed renames
                SELECT c.live_sku, sc.old_sku
                FROM chain c
                JOIN operations.sku_changes sc
                    ON sc.new_sku = c.map_sku
                    AND sc.completed_at IS NOT NULL
            )
            SELECT DISTINCT ON (map_sku) live_sku, map_sku
            FROM chain
            ORDER BY map_sku,
                     CASE WHEN live_sku = map_sku THEN 0 ELSE 1 END,
                     live_sku
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.sku_aliases');
    }
};
