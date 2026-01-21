<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.custom_field_definitions table.
 *
 * Stores custom field schema/metadata from ShopWired.
 * These definitions describe the available custom fields for products,
 * categories, customers, orders, etc.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.custom_field_definitions', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifier (unique constraint creates index automatically)
            $table->integer('external_id')->unique();

            // Field metadata
            $table->string('name', 40);           // Field identifier (snake_case)
            $table->string('type', 20);           // text, toggle, choice, list, date, date_time, value_list, product_list
            $table->string('label', 255)->nullable(); // Human-readable display label
            $table->string('item_type', 20);      // product, category, customer, brand, order, page, blog_post
            $table->integer('sort_order')->nullable();

            // Allowed values for choice/list types
            $table->jsonb('allowed_values')->nullable();

            // Timestamps
            $table->timestampTz('created_at');    // Laravel: when record was first synced
            $table->timestampTz('updated_at');    // Laravel: when record was last updated

            // Indexes for common queries
            $table->index('item_type');           // Filter by product/category/etc
            $table->index('name');                // Lookup by field name
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.custom_field_definitions');
    }
};
