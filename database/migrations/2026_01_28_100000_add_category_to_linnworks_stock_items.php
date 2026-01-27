<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds category fields to linnworks.stock_items table.
 *
 * Linnworks GetStockItemsFull API always returns CategoryId and CategoryName,
 * defaulting to the "Default" category (00000000-0000-0000-0000-000000000000).
 * These fields support the product_enrichment Mixpanel lookup table.
 *
 * @see https://github.com/your-org/alz-core/issues/166
 */
return new class extends Migration {
    private const string DEFAULT_CATEGORY_ID = '00000000-0000-0000-0000-000000000000';
    private const string DEFAULT_CATEGORY_NAME = 'Default';

    public function up(): void
    {
        Schema::table('linnworks.stock_items', static function (Blueprint $table): void {
            $table->string('category_id', 64)
                ->default(self::DEFAULT_CATEGORY_ID)
                ->after('is_composite');

            $table->string('category_name', 255)
                ->default(self::DEFAULT_CATEGORY_NAME)
                ->after('category_id');

            // Index on category_id for potential category-based queries
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.stock_items', static function (Blueprint $table): void {
            $table->dropIndex(['category_id']);
            $table->dropColumn(['category_id', 'category_name']);
        });
    }
};
