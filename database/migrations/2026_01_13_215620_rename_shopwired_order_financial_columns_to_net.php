<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename financial columns to include "net" suffix for clarity.
 *
 * ShopWired API returns NET values for subtotal/shipping fields:
 * - sub_total → sub_total_net (subtotal excluding VAT)
 * - shipping_total → shipping_total_net (shipping cost after discounts, excluding VAT)
 * - original_shipping_total → original_shipping_total_net (shipping before discounts, excluding VAT)
 * - shipping_cost → shipping_charge_net ("charge" is clearer than "cost" for customer-facing amounts)
 *
 * The "total" column remains unchanged as it represents GROSS (including VAT).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->renameColumn('sub_total', 'sub_total_net');
            $table->renameColumn('shipping_total', 'shipping_total_net');
            $table->renameColumn('original_shipping_total', 'original_shipping_total_net');
            $table->renameColumn('shipping_cost', 'shipping_charge_net');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->renameColumn('sub_total_net', 'sub_total');
            $table->renameColumn('shipping_total_net', 'shipping_total');
            $table->renameColumn('original_shipping_total_net', 'original_shipping_total');
            $table->renameColumn('shipping_charge_net', 'shipping_cost');
        });
    }
};
