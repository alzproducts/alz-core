<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.order_items', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_order_id');
            $table->uuid('row_id')->unique();
            $table->uuid('parent_item_id')->nullable();
            $table->uuid('stock_item_id');
            $table->integer('stock_item_int_id')->nullable();
            $table->string('item_number', 100);
            $table->string('sku', 100);
            $table->string('item_source', 100);
            $table->string('title', 500);
            $table->uuid('category_id');
            $table->string('category_name', 200)->nullable();
            $table->integer('quantity');
            $table->decimal('price_per_unit', 10, 4);
            $table->decimal('unit_cost', 10, 4);
            $table->decimal('despatch_stock_unit_cost', 10, 4);
            $table->decimal('discount', 10, 4);
            $table->decimal('tax_rate', 10, 4);
            $table->decimal('cost', 10, 4);
            $table->decimal('cost_inc_tax', 10, 4);
            $table->decimal('sales_tax', 10, 4);
            $table->boolean('tax_cost_inclusive');
            $table->decimal('discount_value', 10, 4);
            $table->decimal('weight', 10, 4);
            $table->string('barcode_number', 200)->nullable();
            $table->string('channel_sku', 200);
            $table->string('channel_title', 500);
            $table->boolean('batch_number_scan_required');
            $table->boolean('serial_number_scan_required');
            $table->boolean('is_service');
            $table->boolean('is_unlinked');
            $table->timestampTz('added_date');
            $table->jsonb('additional_info')->nullable();
            $table->jsonb('bin_racks')->nullable();
            $table->timestampsTz();

            $table->foreign('linnworks_order_id')
                ->references('linnworks_order_id')
                ->on('linnworks.orders')
                ->cascadeOnDelete();

            $table->index('linnworks_order_id');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.order_items');
    }
};
