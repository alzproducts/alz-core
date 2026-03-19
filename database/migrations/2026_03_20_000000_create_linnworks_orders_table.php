<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('linnworks.orders', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linnworks_order_id')->unique();
            $table->integer('num_order_id')->unique();
            $table->boolean('processed');
            $table->timestampTz('last_updated');
            $table->timestampTz('processed_on')->nullable();
            $table->timestampTz('paid_on')->nullable();
            $table->timestampTz('received_date')->nullable();

            // GeneralInfo (flattened)
            $table->string('reference_num', 50);
            $table->string('external_reference_num', 100);
            $table->string('secondary_reference', 100);
            $table->smallInteger('status');
            $table->boolean('hold_or_cancel');
            $table->smallInteger('marker')->nullable();
            $table->boolean('is_parked');
            $table->string('source', 50);
            $table->string('sub_source', 50);
            $table->timestampTz('despatch_by_date')->nullable();
            $table->string('fulfilment_location_id', 36);
            $table->string('location', 36);
            $table->jsonb('folder_names');

            // TotalsInfo (flattened)
            $table->decimal('total_charge', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('tax', 10, 2);
            $table->string('payment_method', 100);

            // ShippingInfo (flattened)
            $table->string('postal_service_name', 100);
            $table->string('vendor', 100);
            $table->decimal('postage_cost', 10, 2);
            $table->decimal('postage_cost_ex_tax', 10, 2);
            $table->string('tracking_number', 200);

            // CustomerInfo — Shipping Address
            $table->string('channel_buyer_name', 200);
            $table->string('ship_email', 200);
            $table->string('ship_full_name', 200);
            $table->string('ship_company', 200);
            $table->string('ship_address1', 200);
            $table->string('ship_address2', 200);
            $table->string('ship_address3', 200);
            $table->string('ship_town', 100);
            $table->string('ship_postcode', 20);
            $table->string('ship_country', 100);

            // CustomerInfo — Billing Address
            $table->string('bill_email', 200);
            $table->string('bill_full_name', 200);
            $table->string('bill_company', 200);
            $table->string('bill_address1', 200);
            $table->string('bill_address2', 200);
            $table->string('bill_address3', 200);
            $table->string('bill_town', 100);
            $table->string('bill_postcode', 20);
            $table->string('bill_country', 100);

            $table->timestampsTz();

            // Indexes for common query patterns
            $table->index('last_updated');
            $table->index('received_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linnworks.orders');
    }
};
