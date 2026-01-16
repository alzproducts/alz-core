<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.product_variations table for product variants.
 *
 * Uses composite unique key (product_external_id, external_id) for stable sync.
 * Internal product_id UUID provides FK relationship; external IDs provide sync semantics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.product_variations', static function (Blueprint $table): void {
            // Primary key (internal)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Relationships
            $table->uuid('product_id');
            $table->foreign('product_id')
                ->references('id')
                ->on('shopwired.products')
                ->cascadeOnDelete();

            // ShopWired identifiers
            $table->integer('product_external_id');  // Parent product's ShopWired ID (stable sync key)
            $table->integer('external_id');           // Variation's ShopWired ID

            // Composite unique: variation within its product (stable external IDs)
            $table->unique(['product_external_id', 'external_id']);

            // Identity
            $table->string('sku', 100)->nullable();

            // Pricing (6dp precision)
            $table->decimal('price', 14, 6);
            $table->decimal('cost_price', 14, 6)->nullable();
            $table->decimal('sale_price', 14, 6)->nullable();

            // Inventory
            $table->integer('stock');

            // Shipping
            $table->decimal('weight', 10, 4)->nullable();

            // Product identifiers
            $table->string('gtin', 50)->nullable();      // Global Trade Item Number
            $table->string('mpn', 100)->nullable();       // Manufacturer Part Number
            $table->string('image_url', 500)->nullable();

            // Options (no default - must be explicitly set)
            $table->jsonb('options');  // Array of {option_id, option_name, value_id, value_name}

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Indexes
            $table->index('product_id');
            $table->index('sku');
            $table->index('product_external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.product_variations');
    }
};
