<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.order_discounts table for order discounts.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.order_discounts', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Relationships
            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')
                ->on('shopwired.orders')
                ->cascadeOnDelete();

            // Discount details
            $table->string('name', 255);
            $table->decimal('value', 10, 2);
            $table->string('type', 50)->nullable();
            $table->string('code', 100)->nullable();
            $table->integer('voucher_id')->nullable();
            $table->integer('offer_id')->nullable();

            // Timestamps
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            // Indexes
            $table->index('order_id');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.order_discounts');
    }
};
