<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the JIT (Just In Time) flag to linnworks.stock_items.
 *
 * JIT identifies drop-shipped items not held in stock. Linnworks reorder
 * reports filter these out to avoid suggesting purchase orders for drop-ship items.
 * Sourced from StockLevels[0].JIT in the GetStockItemsFull API response.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('linnworks.stock_items', static function (Blueprint $table): void {
            $table->boolean('jit')->default(false)->after('minimum_level');
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.stock_items', static function (Blueprint $table): void {
            $table->dropColumn('jit');
        });
    }
};
