<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.stock_item_suppliers', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('stock_item_id', 64)->index();
            $table->string('supplier_id', 64);
            $table->string('supplier_name', 255);
            $table->string('code', 100)->nullable();
            $table->string('supplier_barcode', 100)->nullable();
            $table->decimal('purchase_price', 12, 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('lead_time')->nullable();
            $table->string('supplier_currency', 10)->nullable();
            $table->decimal('min_price', 12, 4)->nullable();
            $table->decimal('max_price', 12, 4)->nullable();
            $table->decimal('average_price', 12, 4)->nullable();
            $table->timestampsTz();

            $table->unique(['stock_item_id', 'supplier_id']);
            $table->index(['stock_item_id', 'is_default']); // For lookup table query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.stock_item_suppliers');
    }
};
