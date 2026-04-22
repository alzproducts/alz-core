<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.custom_field_general_settings table.
 *
 * Stores local (non-ShopWired) configuration layered onto ShopWired custom field
 * definitions. One row per definition; the unique FK enforces the one-to-one
 * relation used by Eloquent hasOne.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.custom_field_general_settings', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('custom_field_definition_id');
            $table->unique('custom_field_definition_id', 'cf_general_settings_definition_id_uniq');
            $table->foreign('custom_field_definition_id', 'cf_general_settings_definition_id_fk')
                ->references('id')
                ->on('shopwired.custom_field_definitions')
                ->cascadeOnDelete();

            $table->text('tooltip')->nullable();
            $table->string('select_type', 20)->nullable();
            $table->boolean('suggest_common_data')->nullable();
            $table->boolean('admin_only')->default(false);
            $table->smallInteger('field_validation_rule')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.custom_field_general_settings');
    }
};
