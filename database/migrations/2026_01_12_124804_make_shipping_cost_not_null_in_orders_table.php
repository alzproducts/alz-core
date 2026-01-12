<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fill NULL values before altering (for future-proofing if data exists)
        DB::statement('UPDATE shopwired.orders SET shipping_cost = 0 WHERE shipping_cost IS NULL');

        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->decimal('shipping_cost', 14, 6)->default(0)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->decimal('shipping_cost', 14, 6)->nullable()->default(null)->change();
        });
    }
};
