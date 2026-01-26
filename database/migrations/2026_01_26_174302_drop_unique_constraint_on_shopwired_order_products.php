<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops unique constraint on shopwired.order_products.
 *
 * The original constraint assumed external_id uniquely identifies a line item within an order.
 * This is incorrect: ShopWired's external_id is the PRODUCT ID, not a line item ID.
 * Multiple line items can share the same external_id when ordering product variations
 * (e.g., "Magiplug - Basin" + "Magiplug - Kitchen Sink" both use Magiplug's product ID).
 *
 * With this constraint dropped, syncProducts() uses delete-all-then-insert (no upsert needed),
 * matching the pattern for discounts/refunds/comments which also lack stable unique identifiers.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.order_products', static function (Blueprint $table): void {
            $table->dropUnique(['order_external_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.order_products', static function (Blueprint $table): void {
            $table->unique(['order_external_id', 'external_id']);
        });
    }
};
