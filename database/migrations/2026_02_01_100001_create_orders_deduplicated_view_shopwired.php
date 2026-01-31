<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create deduplicated orders view for efficient bulk queries.
 *
 * When orders are "edited" in ShopWired, a new order is created with the
 * same `reference` but different `external_id`. The original is cancelled.
 *
 * This view applies DISTINCT ON (reference) to return only the canonical
 * order per reference:
 * 1. Non-cancelled/non-refunded orders take priority
 * 2. Highest external_id wins as tiebreaker (most recent)
 *
 * @see idx_orders_reference_dedup - Expression index optimizing this view
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW shopwired.orders_deduplicated AS
            SELECT DISTINCT ON (reference) *
            FROM shopwired.orders
            ORDER BY reference,
                     CASE WHEN lifecycle_status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END,
                     external_id DESC
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS shopwired.orders_deduplicated');
    }
};
