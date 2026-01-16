<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the linnworks.stock_item_extended_properties table.
 *
 * Stores Extended Properties (EPs) for each stock item. EPs are custom
 * fields merchants define for additional product metadata (supplier codes,
 * material types, certifications, etc.).
 *
 * Sync strategy: Delete all EPs for item → re-insert fresh from API.
 * This handles EP updates/removals without complex diffing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.stock_item_extended_properties', static function (Blueprint $table): void {
            // Primary key (internal)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Foreign key to stock_items (indexed for delete operations)
            $table->string('stock_item_id', 64)->index();

            // Linnworks EP identifier
            $table->string('pk_row_id', 64);

            // Property data
            $table->string('property_name', 255)->index();
            $table->text('property_value');
            $table->string('property_type', 50);

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Composite index for efficient EP lookups by item + name
            $table->index(['stock_item_id', 'property_name'], 'idx_stock_item_property');

            // Foreign key constraint (cascades delete when stock item removed)
            $table->foreign('stock_item_id')
                ->references('stock_item_id')
                ->on('linnworks.stock_items')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.stock_item_extended_properties');
    }
};
