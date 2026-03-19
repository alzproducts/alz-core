<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the operations.price_periods SCD2 table for retail price history.
 *
 * Each row represents a price period for a SKU. When a price changes,
 * the current row is closed (effective_to = now()) and a new row is inserted.
 *
 * The partial unique index (sku WHERE effective_to IS NULL) guarantees
 * exactly one "current" price period per SKU at the database level.
 *
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('operations.price_periods', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->string('sku', 255);

            // Gross prices (tax-inclusive) — ShopWired always stores gross
            $table->decimal('base_price_gross', 14, 6);
            $table->decimal('sale_price_gross', 14, 6)->nullable();
            $table->decimal('effective_price_gross', 14, 6);

            // Tax context — false if zero-rated (no VAT applies)
            $table->boolean('price_has_tax');

            // Period boundaries
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();

            $table->timestampTz('created_at')->useCurrent();
        });

        // Partial unique index: one "current" row per SKU
        DB::statement('
            CREATE UNIQUE INDEX idx_price_periods_current_sku
            ON operations.price_periods (sku)
            WHERE effective_to IS NULL
        ');

        // Composite index for date-range price lookups
        DB::statement('
            CREATE INDEX idx_price_periods_sku_effective_from
            ON operations.price_periods (sku, effective_from)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('operations.price_periods');
    }
};
