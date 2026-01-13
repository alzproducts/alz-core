<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expands order_refunds.name from varchar(255) to TEXT.
 *
 * ShopWired refund descriptions can contain concatenated product details
 * that exceed 255 characters (e.g., bulk refunds with multiple line items).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE shopwired.order_refunds ALTER COLUMN name TYPE TEXT');
    }

    public function down(): void
    {
        // Truncate any values over 255 chars before reverting
        DB::statement('UPDATE shopwired.order_refunds SET name = LEFT(name, 255) WHERE LENGTH(name) > 255');
        DB::statement('ALTER TABLE shopwired.order_refunds ALTER COLUMN name TYPE VARCHAR(255)');
    }
};
