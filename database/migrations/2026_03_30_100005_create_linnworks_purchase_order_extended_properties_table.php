<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.purchase_order_extended_properties', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_purchase_id');
            $table->integer('row_id')->unique()->nullable();

            $table->string('property_name', 200);
            $table->text('property_value');
            $table->string('added_date_time', 50)->nullable();
            $table->string('username', 200)->nullable();

            $table->timestampsTz();

            $table->foreign('linnworks_purchase_id')
                ->references('linnworks_purchase_id')
                ->on('linnworks.purchase_orders')
                ->cascadeOnDelete();

            $table->index('linnworks_purchase_id');
            $table->index('property_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.purchase_order_extended_properties');
    }
};
