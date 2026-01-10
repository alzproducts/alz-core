<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.order_products table for order line items.
 *
 * Note: external_id is ShopWired's product instance ID within the order,
 * confirmed to be globally unique (not just per-order).
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

            // ShopWired identifier (globally unique)
            $table->integer('external_id')->unique();

            // Product info
            $table->string('title', 255);
            $table->string('sku', 100);

            // Pricing (all decimals for financial accuracy)
            $table->decimal('price', 10, 2);
            $table->decimal('price_vat', 10, 2);
            $table->decimal('total', 10, 2);
            $table->decimal('total_vat', 10, 2);
            $table->decimal('original_price', 10, 2);
            $table->decimal('cost_price', 10, 2);

            // Quantity & Tax
            $table->integer('quantity');
            $table->decimal('vat_rate', 5, 2);

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
