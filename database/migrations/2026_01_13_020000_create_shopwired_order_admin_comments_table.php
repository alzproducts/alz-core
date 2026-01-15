<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.order_admin_comments table for admin notes on orders.
 *
 * Admin comments use "replace all" sync strategy - no stable external ID for upsert.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.order_admin_comments', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Relationships
            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')
                ->on('shopwired.orders')
                ->cascadeOnDelete();

            // ShopWired identifiers
            $table->integer('order_external_id'); // Parent order's ShopWired ID
            $table->integer('external_id')->nullable(); // ShopWired comment ID (debugging only)

            // Comment details
            $table->text('content');
            $table->integer('status_id')->nullable(); // Associated ShopWired status ID

            // ShopWired timestamp
            $table->timestampTz('created_at_shopwired')->nullable();

            // Laravel timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Indexes
            $table->index('order_id');
            $table->index('order_external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.order_admin_comments');
    }
};
