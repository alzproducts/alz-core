<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backs title search through the plain catalog.products_view. The expression must match the
 * search predicate exactly or the planner falls back to a sequential scan (ADR-0008).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_shopwired_products_title_search_gin
            ON shopwired.products USING GIN (to_tsvector('english', title))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shopwired.idx_shopwired_products_title_search_gin');
    }
};
