<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.brands table.
 *
 * Stores product brands from ShopWired. Brands are a small, stable
 * dataset (~30 items) synced daily and via webhooks.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.brands', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifier
            $table->integer('external_id')->unique();

            // ShopWired creation timestamp (from API)
            $table->timestampTz('shopwired_created_at');

            // Brand metadata
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('slug', 255);
            $table->string('url', 500);
            $table->boolean('active');
            $table->boolean('featured');
            $table->smallInteger('sort_order');

            // SEO metadata
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords', 500)->nullable();

            // Image
            $table->string('image_url', 500)->nullable();

            // Custom fields (requires custom_fields embed)
            $table->jsonb('custom_fields')->default('{}');

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.brands');
    }
};
