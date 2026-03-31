<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('linnworks.purchase_order_items', static function (Blueprint $table): void {
            $table->decimal('tax_rate', 8, 4)->nullable()->change();
        });

        Schema::table('linnworks.purchase_orders', static function (Blueprint $table): void {
            $table->decimal('shipping_tax_rate', 8, 4)->nullable()->change();
        });

        Schema::table('linnworks.purchase_order_additional_costs', static function (Blueprint $table): void {
            $table->decimal('tax_rate', 8, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.purchase_order_items', static function (Blueprint $table): void {
            $table->decimal('tax_rate', 8, 4)->nullable(false)->change();
        });

        Schema::table('linnworks.purchase_orders', static function (Blueprint $table): void {
            $table->decimal('shipping_tax_rate', 8, 4)->nullable(false)->change();
        });

        Schema::table('linnworks.purchase_order_additional_costs', static function (Blueprint $table): void {
            $table->decimal('tax_rate', 8, 4)->nullable(false)->change();
        });
    }
};
