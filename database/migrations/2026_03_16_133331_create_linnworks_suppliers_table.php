<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.suppliers', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('pk_supplier_id', 64)->unique();
            $table->string('supplier_name');
            $table->string('contact_name')->nullable();
            $table->string('address')->nullable();
            $table->string('alternative_address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country')->nullable();
            $table->string('post_code')->nullable();
            $table->string('telephone_number')->nullable();
            $table->string('secondary_tel_number')->nullable();
            $table->string('fax_number')->nullable();
            $table->string('email')->nullable();
            $table->string('web_page')->nullable();
            $table->string('currency')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.suppliers');
    }
};
