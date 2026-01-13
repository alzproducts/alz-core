<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.order_refunds table for order refunds.
 *
 * Refunds have no stable ID for upsert logic - sync strategy is "replace all"
 * (like discounts). The external_id is stored for debugging purposes only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.order_refunds', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Relationships
            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')
                ->on('shopwired.orders')
                ->cascadeOnDelete();

            // ShopWired identifiers
            $table->integer('order_external_id'); // Parent order's ShopWired ID
            $table->integer('external_id')->nullable(); // ShopWired refund ID (for debugging)

            // Refund details
            $table->string('name', 255); // Description/reason
            $table->decimal('value', 14, 6); // 6dp to preserve raw ShopWired values

            // ShopWired timestamp (when refund was created in ShopWired)
            $table->timestampTz('created_at_shopwired')->nullable();

            // Laravel timestamps (local sync tracking)
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Indexes
            $table->index('order_id');
            $table->index('order_external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.order_refunds');
    }
};
