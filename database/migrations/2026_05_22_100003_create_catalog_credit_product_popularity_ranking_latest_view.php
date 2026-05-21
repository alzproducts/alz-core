<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.credit_product_popularity_ranking_latest view.
 *
 * Cheap read-path view for consumers (drift query, dashboards).
 * Returns all snapshot columns for the most recent snapshot date,
 * ordered by final_score DESC.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.credit_product_popularity_ranking_latest AS
            SELECT *
            FROM catalog.credit_product_popularity_snapshots
            WHERE snapshot_date = (SELECT MAX(snapshot_date) FROM catalog.credit_product_popularity_snapshots)
            ORDER BY final_score DESC
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.credit_product_popularity_ranking_latest');
    }
};
