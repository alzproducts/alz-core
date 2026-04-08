<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE catalog.product_extra_data (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                sku VARCHAR(64) NOT NULL UNIQUE,
                rrp DECIMAL(10,6) NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        // Seed existing RRP values from shopwired.products
        DB::statement(<<<'SQL'
            INSERT INTO catalog.product_extra_data (sku, rrp, created_at, updated_at)
            SELECT sku, compare_price, NOW(), NOW()
            FROM shopwired.products
            WHERE sku IS NOT NULL AND compare_price IS NOT NULL AND compare_price > 0
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS catalog.product_extra_data');
    }
};
