<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds archived and logically-deleted flags to linnworks.stock_items.
 *
 * These flags are sourced from the Linnworks database via the
 * ExecuteCustomScriptQuery SQL endpoint — they are not exposed by the
 * GetStockItemsFull REST API. Required for filtering stock items in
 * reorder reports (Suggested Products by Supplier, Overstocked Items by PO).
 *
 * All existing rows default to false. The first SyncArchivedStockItemFlagsJob
 * run will set the correct values.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('linnworks.stock_items', static function (Blueprint $table): void {
            $table->boolean('is_archived')->default(false)->after('is_composite');
            $table->boolean('is_logically_deleted')->default(false)->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.stock_items', static function (Blueprint $table): void {
            $table->dropColumn(['is_archived', 'is_logically_deleted']);
        });
    }
};
