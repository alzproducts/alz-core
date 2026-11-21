<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `payment_method_raw` (the ShopWired processor string, a `RawPaymentMethod` backing value)
 * to `shopwired.orders`, then re-creates `shopwired.orders_deduplicated` so its `*` picks the
 * column up (COR-298).
 *
 * @see 2026_02_01_100001_create_orders_deduplicated_view_shopwired - view body copied unchanged
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->string('payment_method_raw', 100)->nullable();
        });

        // intentional: CREATE OR REPLACE, never DROP … CASCADE — the catalog views depend on this view (COR-298 Pitfall 1)
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
        // intentional: forward-only (COR-298 plan D3)
        throw new RuntimeException(
            'Migration 2026_11_21_100000 is irreversible: CREATE OR REPLACE VIEW cannot drop the column from shopwired.orders_deduplicated, and DROP VIEW would cascade through the catalog views.',
        );
    }
};
