<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.purchase_order_items', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_purchase_id');
            $table->uuid('linnworks_purchase_item_id')->unique();

            $table->uuid('fk_stock_item_id');
            $table->integer('stock_item_int_id')->nullable();

            // ── Quantities ──
            $table->integer('quantity');
            $table->integer('delivered');
            $table->integer('pack_quantity');
            $table->integer('pack_size');

            // ── Financial ──
            $table->decimal('cost', 12, 4);
            $table->decimal('tax', 12, 4);
            $table->decimal('tax_rate', 8, 4);

            // ── Product ──
            $table->string('sku', 100);
            $table->string('item_title', 500);
            $table->string('barcode_number', 200);
            $table->string('supplier_code', 200);
            $table->string('supplier_barcode', 200);

            // ── Physical ──
            $table->decimal('dim_height', 10, 4);
            $table->decimal('dim_width', 10, 4);
            $table->decimal('dim_depth', 10, 4);

            // ── State ──
            $table->boolean('is_deleted');
            $table->integer('inventory_tracking_type');
            $table->integer('sort_order');

            // ── Warehouse ──
            $table->string('bin_rack', 200);
            $table->integer('bound_to_open_orders_items');
            $table->integer('quantity_bound_to_open_orders_items');

            $table->jsonb('sku_group_ids')->nullable();

            $table->timestampsTz();

            $table->foreign('linnworks_purchase_id')
                ->references('linnworks_purchase_id')
                ->on('linnworks.purchase_orders')
                ->cascadeOnDelete();

            $table->index('linnworks_purchase_id');
            $table->index('fk_stock_item_id');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.purchase_order_items');
    }
};
