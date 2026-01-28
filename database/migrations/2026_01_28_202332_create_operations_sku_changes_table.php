<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the operations.sku_changes audit table for cross-platform SKU updates.
 *
 * Tracks all SKU change attempts between Linnworks and ShopWired.
 * Status is implicit: completed_at NULL = in-progress/failed.
 *
 * This is part of the compensating transaction pattern:
 * 1. Insert record before attempting update
 * 2. Set error_message on failure (compensation triggered)
 * 3. Set completed_at on success
 *
 * @depends 2026_01_28_202330_create_operations_schema
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('operations.sku_changes', static function (Blueprint $table): void {
            $table->id();

            // Linnworks identifier (GUID format)
            $table->uuid('stock_item_id');

            // SKU values
            $table->string('old_sku', 255);
            $table->string('new_sku', 255);

            // Business reason for the change
            $table->string('reason', 50);

            // Error details when update fails
            $table->text('error_message')->nullable();

            // Timestamps: created_at always set, completed_at set on success
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
        });

        // CHECK constraint for valid reason values
        DB::statement("
            ALTER TABLE operations.sku_changes
            ADD CONSTRAINT sku_changes_reason_check
            CHECK (reason IN ('shorten_long_sku', 'fix_sku_mismatch', 'standardize_format', 'merge_products', 'other'))
        ");

        // Partial index for finding incomplete changes (for monitoring/recovery)
        DB::statement('
            CREATE INDEX idx_sku_changes_incomplete
            ON operations.sku_changes (created_at)
            WHERE completed_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('operations.sku_changes');
    }
};
