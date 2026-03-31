<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop columns from purchase_order_items that duplicate data already
 * available in linnworks.stock_items and linnworks.stock_item_suppliers.
 *
 * Read queries can JOIN on fk_stock_item_id to get SKU, title, dimensions, etc.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP INDEX linnworks.linnworks_purchase_order_items_sku_index');

        Schema::table('linnworks.purchase_order_items', static function (Blueprint $table): void {
            $table->dropColumn([
                'stock_item_int_id',
                'sku',
                'item_title',
                'barcode_number',
                'supplier_code',
                'supplier_barcode',
                'dim_height',
                'dim_width',
                'dim_depth',
                'is_deleted',
                'inventory_tracking_type',
                'bin_rack',
                'bound_to_open_orders_items',
                'quantity_bound_to_open_orders_items',
                'sku_group_ids',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.purchase_order_items', static function (Blueprint $table): void {
            $table->integer('stock_item_int_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('item_title', 500)->nullable();
            $table->string('barcode_number', 200)->nullable();
            $table->string('supplier_code', 200)->nullable();
            $table->string('supplier_barcode', 200)->nullable();
            $table->decimal('dim_height', 10, 4)->nullable();
            $table->decimal('dim_width', 10, 4)->nullable();
            $table->decimal('dim_depth', 10, 4)->nullable();
            $table->boolean('is_deleted')->nullable();
            $table->integer('inventory_tracking_type')->nullable();
            $table->string('bin_rack', 200)->nullable();
            $table->integer('bound_to_open_orders_items')->nullable();
            $table->integer('quantity_bound_to_open_orders_items')->nullable();
            $table->jsonb('sku_group_ids')->nullable();

            $table->index('sku');
        });
    }
};
