<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Explicit column list is intentional — prevents accidental exposure of future
        // columns. Update this list when adding columns to order_products.
        DB::statement("
            CREATE VIEW shopwired.order_products_resolved AS
            SELECT
                op.id,
                op.order_id,
                op.order_external_id,
                op.external_id,
                op.title,
                COALESCE(ed.sku_override, op.sku) AS sku,
                op.price,
                op.price_vat,
                op.total,
                op.total_vat,
                op.original_price,
                op.cost_price,
                op.quantity,
                op.vat_rate,
                op.comments,
                op.variation,
                op.custom_fields,
                op.is_preorder,
                op.preorder_date,
                op.variation_hash,
                op.created_at,
                op.updated_at
            FROM shopwired.order_products op
            LEFT JOIN shopwired.order_product_extra_data ed
                ON ed.order_external_id = op.order_external_id
                AND ed.external_id = op.external_id
                AND COALESCE(ed.variation_hash, '') = COALESCE(op.variation_hash, '')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS shopwired.order_products_resolved');
    }
};
