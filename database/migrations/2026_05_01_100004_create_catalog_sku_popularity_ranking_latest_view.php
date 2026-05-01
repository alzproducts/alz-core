<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the catalog.sku_popularity_ranking_latest view.
 *
 * Cheap read-path view for consumers (product_variations_view, APIs).
 * Returns all snapshot columns for the most recent snapshot date,
 * ordered by final_score DESC.
 *
 * Mirrors catalog.product_popularity_ranking_latest.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW catalog.sku_popularity_ranking_latest AS
            SELECT *
            FROM catalog.sku_popularity_snapshots
            WHERE snapshot_date = (SELECT MAX(snapshot_date) FROM catalog.sku_popularity_snapshots)
            ORDER BY final_score DESC
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.sku_popularity_ranking_latest');
    }
};
