<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a GIN expression index for full-text search on product titles.
 *
 * Future DROP/CREATE MATERIALIZED VIEW catalog.products_view migrations must recreate
 * both idx_catalog_products_view_id and this index.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_catalog_products_view_title_search_gin
            ON catalog.products_view USING GIN (to_tsvector('english', title))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalog.idx_catalog_products_view_title_search_gin');
    }
};
