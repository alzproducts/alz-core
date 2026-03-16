<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.categories table.
 *
 * Stores product categories from ShopWired. Categories are a small, stable
 * dataset (~50 items) synced daily and via webhooks.
 *
 * Parent relationships stored as `parent_ids` (jsonb array of external IDs)
 * rather than recursive FK references — the API's parent embed data is
 * incomplete, and integer IDs are sufficient for relationship tracking.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.categories', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifier
            $table->integer('external_id')->unique();

            // ShopWired creation timestamp (from API)
            $table->timestampTz('shopwired_created_at');

            // Category metadata
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('description2')->nullable();
            $table->string('slug', 255);
            $table->string('url', 500);
            $table->boolean('active');
            $table->boolean('featured');
            $table->boolean('trade_only');
            $table->smallInteger('sort_order');

            // SEO metadata
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->boolean('meta_no_index');

            // Image
            $table->string('image_url', 500)->nullable();

            // Parent category external IDs (closest first, root last)
            $table->jsonb('parent_ids')->default('[]');

            // Custom fields (requires custom_fields embed)
            $table->jsonb('custom_fields')->default('{}');

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.categories');
    }
};
