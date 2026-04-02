<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('linnworks.stock_item_suppliers', static function (Blueprint $table): void {
            $table->decimal('average_lead_time', 8, 2)->nullable()->after('average_price');
            $table->integer('supplier_min_order_qty')->nullable()->after('average_lead_time');
            $table->integer('supplier_pack_size')->nullable()->after('supplier_min_order_qty');
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.stock_item_suppliers', static function (Blueprint $table): void {
            $table->dropColumn(['average_lead_time', 'supplier_min_order_qty', 'supplier_pack_size']);
        });
    }
};
