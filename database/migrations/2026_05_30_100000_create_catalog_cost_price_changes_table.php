<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.cost_price_changes table.
 *
 * Append-only audit trail of supplier cost-price deltas (old → new), keyed by SKU + Linnworks
 * supplier GUID. Written as the final best-effort side-effect of UpdateCostPriceBySupplierUseCase.
 * No updated_at — rows are never mutated; multiple changes over time are expected.
 *
 * @depends 2026_03_31_110000_create_catalog_schema
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.cost_price_changes', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Width matches linnworks.stock_items.item_number — narrower would silently
            // reject long SKUs (the best-effort write swallows the insert failure).
            $table->string('sku', 255);
            $table->string('supplier_id', 64);
            $table->string('supplier_name', 255);

            $table->decimal('old_cost_price', 12, 4);
            $table->decimal('new_cost_price', 12, 4);

            $table->timestampTz('changed_at')->useCurrent();

            $table->index(['sku', 'supplier_id']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.cost_price_changes');
    }
};
