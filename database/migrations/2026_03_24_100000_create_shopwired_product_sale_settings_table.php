<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create shopwired.product_sale_settings table.
 *
 * Stores sale metadata for products currently in a sale, persisted so
 * AddToSaleJob reads fresh settings at execution time rather than relying
 * on stale serialized constructor data from dispatch time.
 *
 * One row per product (unique on product_external_id). Rows are created
 * when a product is added to sale and deleted when removed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.product_sale_settings', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->integer('product_external_id')->unique();
            $table->string('sale_reason');
            $table->text('sale_comments')->nullable();
            $table->date('sale_start_date')->nullable();
            $table->date('sale_end_date')->nullable();
            $table->integer('sale_ends_stock')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.product_sale_settings');
    }
};
