<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.order_products table for order line items.
 *
 * Uses composite unique key (order_external_id, external_id) for stable sync.
 * Internal order_id UUID provides FK relationship; external IDs provide sync semantics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.order_products', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Relationships
            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')
                ->on('shopwired.orders')
                ->cascadeOnDelete();

            // ShopWired identifiers
            $table->integer('order_external_id');  // Parent order's ShopWired ID (stable sync key)
            $table->integer('external_id');         // Product line's ShopWired ID

            // Composite unique: product line within its order (stable external IDs)
            $table->unique(['order_external_id', 'external_id']);

            // Product info
            $table->string('title', 255);
            $table->string('sku', 100);

            // Pricing (6dp precision to preserve raw ShopWired values like 14.158, 7.9333)
            $table->decimal('price', 14, 6);
            $table->decimal('price_vat', 14, 6);
            $table->decimal('total', 14, 6);
            $table->decimal('total_vat', 14, 6);
            $table->decimal('original_price', 14, 6);
            $table->decimal('cost_price', 14, 6)->nullable();  // Nullable: older orders may not have cost data

            // Quantity & Tax
            $table->integer('quantity');
            $table->decimal('vat_rate', 5, 1); // Integer rates (20, 0) with 1dp for future half-percent

            // Metadata
            $table->text('comments')->nullable();
            $table->jsonb('variation')->nullable();      // Array of {name, value}
            $table->jsonb('custom_fields')->nullable();  // Dynamic fields

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Indexes
            $table->index('order_id');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.order_products');
    }
};
