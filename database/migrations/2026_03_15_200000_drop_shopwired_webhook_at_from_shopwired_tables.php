<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop shopwired_webhook_at column from products, orders, and customers.
 *
 * Idempotency is now handled by the centralised shopwired.webhook_events table
 * using ShopWired's monotonic webhook_id instead of timestamps.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->dropColumn('shopwired_webhook_at');
        });

        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->dropColumn('shopwired_webhook_at');
        });

        Schema::table('shopwired.customers', static function (Blueprint $table): void {
            $table->dropColumn('shopwired_webhook_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->timestampTz('shopwired_webhook_at')->nullable();
        });

        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->timestampTz('shopwired_webhook_at')->nullable();
        });

        Schema::table('shopwired.customers', static function (Blueprint $table): void {
            $table->timestampTz('shopwired_webhook_at')->nullable();
        });
    }
};
