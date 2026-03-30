<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.purchase_order_additional_costs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_purchase_id');
            $table->integer('linnworks_additional_cost_item_id')->unique()->nullable();

            $table->integer('additional_cost_type_id')->nullable();
            $table->string('reference', 200)->nullable();
            $table->decimal('sub_total_line_cost', 12, 4);
            $table->decimal('tax_rate', 8, 4);
            $table->decimal('tax', 12, 4);
            $table->string('currency', 10)->nullable();
            $table->decimal('conversion_rate', 12, 6);
            $table->decimal('total_line_cost', 12, 4);
            $table->boolean('allocation_locked');
            $table->string('additional_cost_type_name', 200)->nullable();
            $table->boolean('additional_cost_type_is_shipping_type');
            $table->boolean('additional_cost_type_is_partial_allocation');
            $table->boolean('print');
            $table->string('allocation_method', 100)->nullable();
            $table->jsonb('cost_allocation')->nullable();

            $table->timestampsTz();

            $table->foreign('linnworks_purchase_id')
                ->references('linnworks_purchase_id')
                ->on('linnworks.purchase_orders')
                ->cascadeOnDelete();

            $table->index('linnworks_purchase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.purchase_order_additional_costs');
    }
};
