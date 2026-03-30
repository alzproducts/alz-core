<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.purchase_orders', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_purchase_id')->unique();

            // ── Header — Identifiers ──
            $table->uuid('fk_supplier_id');
            $table->uuid('fk_location_id');
            $table->string('external_invoice_number', 200);

            // ── Header — Status ──
            $table->string('status', 20);
            $table->boolean('locked');

            // ── Header — Counts ──
            $table->integer('line_count');
            $table->integer('delivered_lines_count');

            // ── Header — Financial ──
            $table->string('currency', 10);
            $table->string('supplier_reference_number', 200);
            $table->integer('unit_amount_tax_included_type');
            $table->decimal('postage_paid', 12, 4);
            $table->decimal('total_cost', 12, 4);
            $table->decimal('tax_paid', 12, 4);
            $table->decimal('shipping_tax_rate', 8, 4);
            $table->decimal('conversion_rate', 12, 6);
            $table->decimal('converted_shipping_cost', 12, 4);
            $table->decimal('converted_shipping_tax', 12, 4);
            $table->decimal('converted_other_cost', 12, 4);
            $table->decimal('converted_other_tax', 12, 4);
            $table->decimal('converted_grand_total', 12, 4);

            // ── Header — Dates ──
            $table->timestampTz('date_of_purchase')->nullable();
            $table->timestampTz('date_of_delivery')->nullable();
            $table->timestampTz('quoted_delivery_date')->nullable();

            // ── Core extras ──
            $table->integer('note_count')->default(0);
            $table->timestampTz('synced_at');

            $table->timestampsTz();

            // Indexes for common query patterns
            $table->index('fk_supplier_id');
            $table->index('status');
            $table->index('date_of_purchase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.purchase_orders');
    }
};
