<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.free_delivery_shipping_costs lookup table.
 *
 * Stores the VAT-exclusive cost the business absorbs per single-unit
 * free-delivery order, keyed by the delivery_type string used in
 * ShopWired's `free_delivery` custom field (matches the `FreeDeliveryType`
 * enum casing exactly: 'Standard', 'Express').
 *
 * Consumed by catalog.products_view / catalog.product_variations_view to
 * compute `net_margin_single_unit` (worst-case margin when one item ships).
 * Update rows in place when carrier prices change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.free_delivery_shipping_costs', static function (Blueprint $table): void {
            $table->string('delivery_type')->primary();
            $table->decimal('cost', 8, 2);
        });

        DB::table('catalog.free_delivery_shipping_costs')->insert([
            ['delivery_type' => 'Standard', 'cost' => 3.50],
            ['delivery_type' => 'Express', 'cost' => 4.50],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.free_delivery_shipping_costs');
    }
};
