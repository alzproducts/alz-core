<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove unique constraint on email in shopwired.customers.
 *
 * ShopWired allows duplicate emails across customer accounts (e.g., same person
 * with both trade and non-trade accounts). The unique constraint was causing
 * 7 customers to fail during sync.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.customers', static function (Blueprint $table): void {
            $table->dropUnique('shopwired_customers_email_unique');
            $table->index('email', 'shopwired_customers_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.customers', static function (Blueprint $table): void {
            $table->dropIndex('shopwired_customers_email_index');
            $table->unique('email', 'shopwired_customers_email_unique');
        });
    }
};
