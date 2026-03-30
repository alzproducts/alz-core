<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.purchase_order_notes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_purchase_id');
            $table->uuid('linnworks_purchase_order_note_id')->unique();

            $table->text('note');
            $table->timestampTz('date_time')->nullable();
            $table->string('user_name', 255)->nullable();

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
        Schema::dropIfExists('linnworks.purchase_order_notes');
    }
};
