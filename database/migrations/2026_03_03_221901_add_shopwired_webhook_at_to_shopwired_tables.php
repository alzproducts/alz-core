<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add shopwired_webhook_at column to orders, products, and customers tables.
 *
 * Tracks the timestamp of the most recent webhook event processed for each entity,
 * enabling idempotency (reject stale/duplicate webhook events) and reconciliation
 * freshness checks.
 *
 * Separate from:
 * - updated_at: Laravel sync time
 * - shopwired_updated_at / order_placed_at: ShopWired business time
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->timestampTz('shopwired_webhook_at')->nullable();
        });

        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->timestampTz('shopwired_webhook_at')->nullable();
        });

        Schema::table('shopwired.customers', static function (Blueprint $table): void {
            $table->timestampTz('shopwired_webhook_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->dropColumn('shopwired_webhook_at');
        });

        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->dropColumn('shopwired_webhook_at');
        });

        Schema::table('shopwired.customers', static function (Blueprint $table): void {
            $table->dropColumn('shopwired_webhook_at');
        });
    }
};
