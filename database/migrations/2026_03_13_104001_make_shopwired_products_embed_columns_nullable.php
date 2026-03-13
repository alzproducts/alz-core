<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Harden embed-dependent columns on shopwired.products for webhook compatibility.
 *
 * ShopWired webhooks don't include embed data (vat_relief, categories, images, etc.).
 * Making vat_relief nullable allows webhook-created rows to have null ("unknown")
 * until the full API sync fills in the real value.
 *
 * JSONB columns get defaults so INSERT without those columns doesn't fail.
 * (filters already has DEFAULT '{}' from its creation migration.)
 */
return new class extends Migration {
    public function up(): void
    {
        // vat_relief: null = "unknown/not yet synced", false = "confirmed not eligible"
        DB::statement('ALTER TABLE shopwired.products ALTER COLUMN vat_relief DROP NOT NULL');

        // JSONB embed columns: add defaults for INSERT safety
        DB::statement("ALTER TABLE shopwired.products ALTER COLUMN category_ids SET DEFAULT '[]'");
        DB::statement("ALTER TABLE shopwired.products ALTER COLUMN images SET DEFAULT '[]'");
        DB::statement("ALTER TABLE shopwired.products ALTER COLUMN custom_fields SET DEFAULT '{}'");
    }

    public function down(): void
    {
        // Restore NOT NULL (backfill nulls first to avoid constraint violation)
        DB::statement('UPDATE shopwired.products SET vat_relief = false WHERE vat_relief IS NULL');
        DB::statement('ALTER TABLE shopwired.products ALTER COLUMN vat_relief SET NOT NULL');

        // Remove defaults
        DB::statement('ALTER TABLE shopwired.products ALTER COLUMN category_ids DROP DEFAULT');
        DB::statement('ALTER TABLE shopwired.products ALTER COLUMN images DROP DEFAULT');
        DB::statement('ALTER TABLE shopwired.products ALTER COLUMN custom_fields DROP DEFAULT');
    }
};
