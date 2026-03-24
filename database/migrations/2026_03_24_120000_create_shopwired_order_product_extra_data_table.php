<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.order_product_extra_data', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->integer('order_external_id');
            $table->integer('external_id');
            $table->string('variation_hash', 32)->nullable();
            $table->string('sku_override', 100)->nullable();
            $table->timestampsTz();
        });

        // Functional unique index using COALESCE to treat NULL variation_hash as ''
        // Laravel's $table->unique() doesn't support expressions, so we use raw SQL.
        DB::statement("
            CREATE UNIQUE INDEX order_product_extra_data_oed_eid_vhash_unique
            ON shopwired.order_product_extra_data (order_external_id, external_id, COALESCE(variation_hash, ''))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.order_product_extra_data');
    }
};
