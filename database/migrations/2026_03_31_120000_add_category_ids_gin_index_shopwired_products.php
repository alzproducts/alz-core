<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a GIN index on category_ids JSONB column for efficient whereJsonContains filtering.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_products_category_ids_gin
            ON shopwired.products USING GIN (category_ids)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shopwired.idx_products_category_ids_gin');
    }
};
