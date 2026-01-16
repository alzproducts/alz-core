<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.products table for local product persistence.
 *
 * Design: Stores all Domain Product fields for complete round-trip fidelity.
 * Uses JSONB for category_ids (array of ints), images (array of objects),
 * and custom_fields (raw API response for typed hydration at read time).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.products', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifier (unique constraint creates index automatically)
            $table->integer('external_id')->unique();

            // Identity
            $table->string('sku', 100)->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('slug', 255);
            $table->string('url', 500);

            // Pricing (6dp precision to preserve raw ShopWired values)
            $table->decimal('price', 14, 6);
            $table->decimal('cost_price', 14, 6)->nullable();
            $table->decimal('sale_price', 14, 6)->nullable();
            $table->decimal('compare_price', 14, 6)->nullable();

            // Inventory (null for products with variations)
            $table->integer('stock')->nullable();

            // Flags (no defaults - must be explicitly set on insert)
            $table->boolean('is_active');
            $table->boolean('vat_exclusive');
            $table->boolean('vat_relief');

            // Shipping
            $table->decimal('weight', 10, 4)->nullable();

            // SEO
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();

            // Relations (no defaults - must be explicitly set on insert)
            $table->jsonb('category_ids');   // Array of int category IDs
            $table->jsonb('images');          // Array of {id, url, description, sort_order}
            $table->jsonb('custom_fields');   // Raw {name: value} from API

            // Timestamps
            $table->timestampTz('shopwired_created_at');
            $table->timestampTz('shopwired_updated_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Indexes
            $table->index('sku');
            $table->index('is_active');
            $table->index('shopwired_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.products');
    }
};
