<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.custom_field_product_settings table.
 *
 * Product-specific local configuration. Only valid for definitions whose item_type
 * is 'product'; the ConfiguredFieldDefinition Domain VO enforces that invariant.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.custom_field_product_settings', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('custom_field_definition_id')->unique();
            $table->foreign('custom_field_definition_id')
                ->references('id')
                ->on('shopwired.custom_field_definitions')
                ->cascadeOnDelete();

            $table->string('update_linnworks_stock_item', 20)->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.custom_field_product_settings');
    }
};
