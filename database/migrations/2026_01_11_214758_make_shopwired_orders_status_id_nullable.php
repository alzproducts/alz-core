<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes status_id nullable in shopwired.orders table.
 *
 * ShopWired custom statuses have no numeric ID - only built-in statuses
 * (Paid, Dispatched, etc.) have IDs. Custom statuses return null.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->integer('status_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->integer('status_id')->nullable(false)->change();
        });
    }
};
