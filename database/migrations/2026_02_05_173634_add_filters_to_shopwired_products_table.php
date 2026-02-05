<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add filters column to shopwired.products table.
 *
 * Stores raw filter data from ShopWired API as JSONB.
 * Format: {"optionNo": ["value1", "value2"], ...}
 * Example: {"1": ["Small", "Medium"], "2": ["Yes"]}
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->jsonb('filters')->default('{}');
        });

        // GIN index for efficient JSONB queries (e.g., find products with specific filter values)
        Schema::raw('CREATE INDEX products_filters_gin_idx ON shopwired.products USING GIN (filters)');
    }

    public function down(): void
    {
        Schema::raw('DROP INDEX IF EXISTS shopwired.products_filters_gin_idx');

        Schema::table('shopwired.products', static function (Blueprint $table): void {
            $table->dropColumn('filters');
        });
    }
};
