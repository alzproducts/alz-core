<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds missing scalar fields from ShopWired API to orders and order_products tables.
 *
 * Phase 3 of Issue #112: Complete ShopWired order domain model.
 *
 * Orders table additions:
 * - Archive/anonymization flags
 * - Shipping ID and original shipping total
 * - Tracking/invoice URLs
 * - Transaction ID, delivery date, package weight
 * - Tax value and VAT calculation flag
 * - Country IDs for addresses
 * - Pre-order status (derived from products)
 *
 * Order products table additions:
 * - Pre-order flag and expected date
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            // Archive/anonymization flags
            $table->boolean('is_archived')->default(false)->after('has_vat_relief');
            $table->boolean('is_anonymized')->default(false)->after('is_archived');

            // Shipping method ID (distinct from shipping_method name)
            $table->integer('shipping_id')->nullable()->after('shipping_method');

            // Original shipping total (before discounts)
            $table->decimal('original_shipping_total', 14, 6)->nullable()->after('shipping_total');

            // Tracking and invoice URLs
            $table->string('tracking_url', 2048)->nullable()->after('shipping_vat_rate');
            $table->string('invoice_url', 2048)->nullable()->after('tracking_url');

            // Payment/transaction
            $table->string('transaction_id', 255)->nullable()->after('payment_method');

            // Delivery scheduling
            $table->date('delivery_date')->nullable()->after('order_placed_at');

            // Package info
            $table->string('package_weight', 50)->nullable()->after('delivery_date');

            // Tax
            $table->decimal('tax_value', 14, 6)->nullable()->after('sub_total');
            $table->boolean('line_item_vat_calculation')->default(false)->after('tax_value');

            // Country IDs for addresses (0 = unknown, for existing records)
            $table->integer('billing_country_id')->default(0)->after('billing_country');
            $table->integer('delivery_country_id')->default(0)->after('delivery_country');

            // Pre-order status (derived from product-level flags)
            // Values: 'none', 'partial', 'full'
            $table->string('pre_order_status', 10)->default('none')->after('lifecycle_status');
        });

        Schema::table('shopwired.order_products', static function (Blueprint $table): void {
            // Pre-order tracking
            $table->boolean('is_preorder')->default(false)->after('custom_fields');
            $table->date('preorder_date')->nullable()->after('is_preorder');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->dropColumn([
                'is_archived',
                'is_anonymized',
                'shipping_id',
                'original_shipping_total',
                'tracking_url',
                'invoice_url',
                'transaction_id',
                'delivery_date',
                'package_weight',
                'tax_value',
                'line_item_vat_calculation',
                'billing_country_id',
                'delivery_country_id',
                'pre_order_status',
            ]);
        });

        Schema::table('shopwired.order_products', static function (Blueprint $table): void {
            $table->dropColumn([
                'is_preorder',
                'preorder_date',
            ]);
        });
    }
};
