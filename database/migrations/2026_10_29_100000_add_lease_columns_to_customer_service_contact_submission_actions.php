<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lease-and-return columns (ADR-0012) plus the quote details the sweeper needs to
 * rebuild a quote command from the row. All nullable with no backfill: rows created
 * before deploy carry no lease or quote details.
 *
 * @depends 2026_05_23_002841_add_ad_platform_to_customer_service_contact_submission_actions
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_service.contact_submission_actions', static function (Blueprint $table): void {
            $table->timestampTz('next_attempt_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->decimal('quote_value_net', 14, 6)->nullable();
            $table->timestampTz('quote_converted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_service.contact_submission_actions', static function (Blueprint $table): void {
            $table->dropColumn(['next_attempt_at', 'claim_token', 'quote_value_net', 'quote_converted_at']);
        });
    }
};
