<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the linnworks.stock_items table for inventory sync.
 *
 * Stores ~10k stock items from Linnworks with daily full-refresh sync.
 * Schema matches Domain StockItem VO with flat storage for Dimensions/Weight.
 *
 * Nullable fields preserve Linnworks source fidelity:
 * - NULL = "Linnworks didn't provide this value"
 * - 0 = "Linnworks explicitly returned zero"
 * The mapper layer converts NULL → zero VOs when building domain objects.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.stock_items', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Linnworks identifier (unique constraint creates index automatically)
            $table->string('stock_item_id', 64)->unique();

            // Identity (always present in Linnworks)
            $table->string('item_number', 255)->index();
            $table->string('item_title', 500);
            $table->string('barcode', 100)->nullable();

            // Stock levels (nullable to preserve source fidelity)
            $table->integer('quantity')->nullable();
            $table->integer('available')->nullable();
            $table->integer('in_order')->nullable();
            $table->integer('due')->nullable();
            $table->integer('minimum_level')->nullable();

            // Pricing (nullable - may not be set for all items)
            $table->decimal('purchase_price', 12, 4)->nullable();
            $table->decimal('retail_price', 12, 4)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();

            // Weight (nullable - physical measurement may not be recorded)
            $table->decimal('weight', 12, 4)->nullable();
            $table->string('weight_unit', 20)->nullable();

            // Dimensions (nullable - physical measurements may not be recorded)
            $table->decimal('height', 12, 4)->nullable();
            $table->decimal('width', 12, 4)->nullable();
            $table->decimal('depth', 12, 4)->nullable();

            // Flags (boolean with sensible default)
            $table->boolean('is_composite')->default(false);

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.stock_items');
    }
};
