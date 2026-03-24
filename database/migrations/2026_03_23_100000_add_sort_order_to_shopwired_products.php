<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add sort_order column to shopwired.products table.
 *
 * Stores the ShopWired product sort order so it can be preserved
 * when adding/removing products from the sale category.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->integer('sort_order')->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
