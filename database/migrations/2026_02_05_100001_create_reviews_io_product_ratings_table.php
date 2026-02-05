<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the product_ratings table for caching Reviews.io ratings.
 *
 * Stores SKU-level ratings fetched from Reviews.io API for use in
 * ShopWired custom field updates. Upsert by SKU with latest-wins semantics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviews_io.product_ratings', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('sku', 255)->unique();
            // decimal(6, 5) allows 0.00000 to 9.99999 - enough precision for rounding buffer
            $table->decimal('average_rating', 6, 5)->nullable(); // NULL = no reviews
            $table->unsignedInteger('num_ratings')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews_io.product_ratings');
    }
};
