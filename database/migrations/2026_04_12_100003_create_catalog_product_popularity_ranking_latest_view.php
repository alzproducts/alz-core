<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.product_popularity_ranking_latest view.
 *
 * Cheap read-path view for consumers (dashboards, APIs).
 * Returns all snapshot columns for the most recent snapshot date,
 * ordered by final_score DESC.
 *
 * Fast because:
 *   - MAX(snapshot_date) subquery is an index-only scan on the composite PK
 *   - Outer query becomes a PK range scan on all rows for that date
 */
return new class extends Migration {
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
};
