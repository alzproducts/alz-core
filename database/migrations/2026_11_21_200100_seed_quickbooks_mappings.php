<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the semantic mapping keys with no QBO refs; the resolve command fills refs per environment.
 * Inserts only missing keys, so a resolved ref survives a re-run.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO quickbooks.mappings (key, qbo_entity) VALUES
                ('tax.standard', 'TaxCode'),
                ('tax.zero', 'TaxCode'),
                ('tax.eu_goods', 'TaxCode'),
                ('tax.eu_shipping', 'TaxCode'),
                ('deposit_account.paypal', 'Account'),
                ('deposit_account.card_opayo', 'Account'),
                ('deposit_account.shopwired_payments', 'Account'),
                ('payment_method.paypal', 'PaymentMethod'),
                ('payment_method.card_opayo', 'PaymentMethod'),
                ('payment_method.shopwired_payments', 'PaymentMethod'),
                ('department', 'Department'),
                ('class', 'Class'),
                ('default_terms', 'Term'),
                ('custom_field.po_number', 'CustomField'),
                ('custom_field.transaction_id', 'CustomField')
            ON CONFLICT (key) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::statement('DELETE FROM quickbooks.mappings WHERE qbo_ref_id IS NULL');
    }
};
