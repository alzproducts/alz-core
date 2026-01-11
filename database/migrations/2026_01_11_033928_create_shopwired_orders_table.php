<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.orders table for local order persistence.
 *
 * Design: Embedded value objects extracted to columns for query flexibility
 * and migration simplicity. Exception: custom_fields stays JSONB (truly dynamic).
 *
 * Naming conventions:
 * - billing_*  : Billing address fields
 * - delivery_* : Shipping address fields (distinct from shipping method)
 * - shipping_* : Shipping method fields
 * - customer_* : Customer snapshot fields
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.orders', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifiers (unique constraints create indexes automatically)
            $table->integer('external_id')->unique();
            $table->integer('reference')->unique();

            // Financials (6dp precision to preserve raw ShopWired values)
            $table->decimal('total', 14, 6);
            $table->decimal('sub_total', 14, 6);
            $table->decimal('shipping_total', 14, 6);

            // Status (status_id nullable - custom statuses have no numeric ID)
            $table->integer('status_id')->nullable();
            $table->string('status_name', 255);
            $table->string('status_type', 50);
            $table->string('lifecycle_status', 50);

            // Customer snapshot
            $table->integer('customer_id');
            $table->smallInteger('customer_type');
            $table->date('customer_date_of_birth')->nullable();
            $table->jsonb('customer_device_info')->nullable(); // Flexible attribution bag

            // Billing address
            $table->string('billing_name', 255);
            $table->string('billing_email', 255);
            $table->string('billing_telephone', 50)->nullable();
            $table->string('billing_company', 255)->nullable();
            $table->string('billing_address_line1', 255);
            $table->string('billing_address_line2', 255)->nullable();
            $table->string('billing_address_line3', 255)->nullable();
            $table->string('billing_city', 100);
            $table->string('billing_province', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_postcode', 20);
            $table->string('billing_country', 100);

            // Delivery address (shipping destination)
            $table->string('delivery_name', 255);
            $table->string('delivery_email', 255);
            $table->string('delivery_telephone', 50)->nullable();
            $table->string('delivery_company', 255)->nullable();
            $table->string('delivery_address_line1', 255);
            $table->string('delivery_address_line2', 255)->nullable();
            $table->string('delivery_address_line3', 255)->nullable();
            $table->string('delivery_city', 100);
            $table->string('delivery_province', 100)->nullable();
            $table->string('delivery_state', 100)->nullable();
            $table->string('delivery_postcode', 20);
            $table->string('delivery_country', 100);

            // Shipping method (nullable - not all orders have shipping)
            $table->string('shipping_method', 255)->nullable();
            $table->decimal('shipping_cost', 14, 6)->nullable();
            $table->decimal('shipping_vat_rate', 5, 1)->nullable(); // Integer rates with 1dp for half-percent

            // Payment
            $table->string('payment_method', 100);

            // Flags
            $table->boolean('marketing')->default(false);
            $table->boolean('has_vat_relief')->default(false);

            // Metadata
            $table->text('comments')->nullable();
            $table->jsonb('custom_fields')->nullable(); // Dynamic fields - future: separate table

            // Timestamps
            $table->timestampTz('order_placed_at');  // ShopWired's order creation date (business data)
            $table->timestampTz('created_at');       // Laravel: when record was first synced
            $table->timestampTz('updated_at');       // Laravel: when record was last updated

            // Indexes (external_id and reference already indexed via UNIQUE constraint)
            $table->index('lifecycle_status');
            $table->index('status_type');
            $table->index('customer_id');
            $table->index('order_placed_at');
            $table->index('delivery_country');
            $table->index('delivery_postcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.orders');
    }
};
