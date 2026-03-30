<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.purchase_order_delivered_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_purchase_id');
            $table->integer('linnworks_delivery_record_id')->unique();

            $table->uuid('fk_purchase_item_id');
            $table->uuid('fk_stock_location_id');
            $table->decimal('unit_cost', 12, 4);
            $table->integer('delivered_quantity');
            $table->timestampTz('created_date_time')->nullable();

            $table->timestampsTz();

            $table->foreign('linnworks_purchase_id')
                ->references('linnworks_purchase_id')
                ->on('linnworks.purchase_orders')
                ->cascadeOnDelete();

            $table->index('linnworks_purchase_id');
            $table->index('fk_purchase_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.purchase_order_delivered_records');
    }
};
