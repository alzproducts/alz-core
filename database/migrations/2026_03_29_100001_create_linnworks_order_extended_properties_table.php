<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.order_extended_properties', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_order_id');
            $table->uuid('row_id')->unique();
            $table->string('name', 200);
            $table->text('value');
            $table->string('type', 50);
            $table->timestampTz('create_date')->nullable();
            $table->timestampTz('last_update')->nullable();
            $table->string('updated_by', 200)->nullable();
            $table->timestampsTz();

            $table->foreign('linnworks_order_id')
                ->references('linnworks_order_id')
                ->on('linnworks.orders')
                ->cascadeOnDelete();

            $table->index('linnworks_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.order_extended_properties');
    }
};
