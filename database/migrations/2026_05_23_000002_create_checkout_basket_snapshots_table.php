<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the checkout.basket_snapshots table.
 *
 * Captures pre-checkout basket state for fuzzy matching against completed orders.
 * Workaround for ShopWired losing basket_comments on Safari/Apple checkout submissions.
 *
 * Immutable insert-only — no updated_at column. Matched to completed orders
 * post-hoc by (ip_address, basket_total) within a short time window.
 *
 * @depends 2026_05_23_000001_create_checkout_schema
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout.basket_snapshots', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Fuzzy-match identifiers (captured server-side, never trusted from client)
            $table->ipAddress('ip_address');
            $table->text('user_agent');

            // Basket total (inc-VAT) — primary match key alongside ip_address
            $table->decimal('basket_total', 10, 2);

            // Lost-on-Safari basket_comments fields
            $table->string('shipping_method_id', 100)->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('gift_note', 500)->nullable();
            $table->jsonb('vat_relief')->nullable();

            // Timestamp (immutable — no updated_at)
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("COMMENT ON COLUMN checkout.basket_snapshots.ip_address IS 'Internal: captured server-side for fuzzy matching against completed orders'");
        DB::statement("COMMENT ON COLUMN checkout.basket_snapshots.basket_total IS 'Inc-VAT basket total at submit time; primary match key alongside ip_address'");
        DB::statement("COMMENT ON COLUMN checkout.basket_snapshots.vat_relief IS 'JSONB blob: full VAT-relief declaration form fields submitted at checkout'");

        // Composite index for fuzzy matching: filter by (ip_address, basket_total)
        // then narrow to a recent time window. Including created_at lets Postgres
        // satisfy the whole WHERE predicate from the index.
        DB::statement('CREATE INDEX idx_basket_snapshots_ip_total_created ON checkout.basket_snapshots(ip_address, basket_total, created_at)');
        // Index for cleanup queries (delete snapshots older than N days)
        DB::statement('CREATE INDEX idx_basket_snapshots_created_at ON checkout.basket_snapshots(created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout.basket_snapshots');
    }
};
