<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add expression index for efficient order deduplication.
 *
 * This index supports the DISTINCT ON (reference) query pattern used
 * by the orders_deduplicated view. The expression columns match the
 * ORDER BY clause exactly for optimal index-only scans.
 *
 * @see shopwired.orders_deduplicated - View using this index
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_orders_reference_dedup
            ON shopwired.orders (
                reference,
                (CASE WHEN lifecycle_status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END),
                external_id DESC
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shopwired.idx_orders_reference_dedup');
    }
};
