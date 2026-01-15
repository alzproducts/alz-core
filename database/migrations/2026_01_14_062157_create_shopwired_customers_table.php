<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the shopwired.customers table for local customer persistence.
 *
 * Design: Stores all Domain Customer fields for complete round-trip fidelity.
 * Address is flattened to columns (same pattern as orders table).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.customers', static function (Blueprint $table): void {
            // Primary key (internal, never exposed to Domain)
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ShopWired identifier (unique constraint creates index automatically)
            $table->integer('external_id')->unique();

            // Identity
            $table->string('email', 255)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('company_name', 255)->nullable();

            // Classification
            $table->boolean('is_trade')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_credit_enabled')->nullable();

            // Contact
            $table->string('phone', 50)->nullable();
            $table->string('mobile_phone', 50)->nullable();
            $table->boolean('accepts_marketing')->default(false);

            // Address (flattened value object)
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('address_line3', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postcode', 20)->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Custom fields (JSONB for flexibility)
            $table->jsonb('custom_fields')->nullable();

            // Timestamps
            $table->timestampTz('shopwired_created_at');  // From ShopWired API (business data)
            $table->timestampTz('created_at');             // Laravel: when record was first synced
            $table->timestampTz('updated_at');             // Laravel: when record was last updated

            // Indexes (external_id and email already indexed via UNIQUE constraints)
            $table->index('is_trade');
            $table->index('is_active');
            $table->index('shopwired_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.customers');
    }
};
