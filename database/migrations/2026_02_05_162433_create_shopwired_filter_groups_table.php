<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.filter_groups table.
 *
 * Stores filter group definitions from ShopWired. Filter groups define
 * faceted navigation categories (e.g., "Size", "Colour", "VAT Relief Eligible").
 * Products can have filter values assigned per group.
 *
 * Key field: `option_no` is used as the key in product filter data
 * (e.g., `{"1": ["Small", "Large"]}` where 1 is the option_no).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.filter_groups', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifier
            $table->integer('external_id')->unique();

            // Filter group metadata
            $table->string('title', 255);
            $table->integer('option_no')->unique(); // Used as key in product filters JSON
            $table->smallInteger('sort_order'); // Display ordering

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Index for lookup by option_no (primary lookup path when resolving product filters)
            $table->index('option_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.filter_groups');
    }
};
