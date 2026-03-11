<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add unique constraint on (order_external_id, external_id) to order_refunds.
 *
 * Refunds DO have a stable ShopWired ID (external_id), contrary to the original
 * migration comment. This constraint enables idempotent webhook inserts via upsert.
 *
 * Also makes external_id non-nullable since the API always provides it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.order_refunds', static function (Blueprint $table): void {
            $table->integer('external_id')->nullable(false)->change();
            $table->unique(['order_external_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.order_refunds', static function (Blueprint $table): void {
            $table->dropUnique(['order_external_id', 'external_id']);
            $table->integer('external_id')->nullable()->change();
        });
    }
};
