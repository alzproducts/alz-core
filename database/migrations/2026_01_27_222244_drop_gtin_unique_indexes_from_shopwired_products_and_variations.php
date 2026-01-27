<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove GTIN unique constraints from products and variations tables.
 *
 * Rationale: ShopWired allows duplicate GTINs across products (common in real
 * inventory data). Uniqueness is a data quality concern better served by SQL
 * reporting queries rather than hard database constraints that block sync.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS shopwired.products_gtin_unique');
        DB::statement('DROP INDEX IF EXISTS shopwired.product_variations_gtin_unique');
    }

    public function down(): void
    {
        // Recreate partial unique indexes on GTIN
        DB::statement('
            CREATE UNIQUE INDEX products_gtin_unique
            ON shopwired.products (gtin)
            WHERE gtin IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX product_variations_gtin_unique
            ON shopwired.product_variations (gtin)
            WHERE gtin IS NOT NULL
        ');
    }
};
