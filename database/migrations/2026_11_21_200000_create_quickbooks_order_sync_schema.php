<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QBO order sync state: one sync row per realm and order reference, an append-only error log,
 * the semantic mapping keys and the sync-source view. Only alz-core reads it, through its BYPASSRLS role.
 */
return new class extends Migration {
    /** @var list<string> */
    private const array SUPABASE_ROLES = ['anon', 'authenticated', 'service_role'];

    public function up(): void
    {
        $schema = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'quickbooks') AS present");
        if ($schema === null || ! $schema->present) {
            DB::statement('CREATE SCHEMA quickbooks');
        }

        DB::statement(<<<'SQL'
            CREATE TABLE quickbooks.order_syncs (
                id                 uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
                realm_id           text        NOT NULL,
                reference          integer     NOT NULL,
                order_external_id  integer     NOT NULL,
                doc_type           text        NULL CHECK (doc_type IN ('invoice', 'sales_receipt')),
                status             text        NOT NULL DEFAULT 'pending'
                                               CHECK (status IN ('pending', 'processing', 'synced', 'failed')),
                qbo_txn_id         text        NULL,
                qbo_doc_number     text        NULL,
                attempts           integer     NOT NULL DEFAULT 0,
                last_attempted_at  timestamptz NULL,
                last_error_code    text        NULL,
                last_error_message text        NULL,
                claim_token        uuid        NULL,
                lease_expires_at   timestamptz NULL,
                payload_hash       text        NULL,
                synced_at          timestamptz NULL,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT order_syncs_realm_id_reference_unique UNIQUE (realm_id, reference)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE quickbooks.order_sync_errors (
                id                bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                order_sync_id     uuid        NOT NULL REFERENCES quickbooks.order_syncs (id) ON DELETE CASCADE,
                trigger           text        NOT NULL CHECK (trigger IN ('range', 'manual')),
                run_id            text        NULL,
                reason_code       text        NOT NULL,
                qbo_fault_code    text        NULL,
                qbo_fault_element text        NULL,
                message           text        NULL,
                created_at        timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX order_sync_errors_order_sync_id_index ON quickbooks.order_sync_errors (order_sync_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE quickbooks.mappings (
                id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key          text        NOT NULL UNIQUE,
                qbo_entity   text        NOT NULL
                                         CHECK (qbo_entity IN ('TaxCode', 'Account', 'PaymentMethod', 'Department', 'Class', 'Term', 'CustomField')),
                qbo_ref_id   text        NULL,
                qbo_ref_name text        NULL,
                realm_id     text        NULL,
                resolved_at  timestamptz NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // intentional: no sync-status join and no eligibility filter; the builder decides skips (COR-301 D7).
        // Money and rates are cast to text so the source scale survives (ADR-0022).
        DB::statement(<<<'SQL'
            CREATE VIEW quickbooks.order_sync_source AS
            SELECT
                o.reference,
                o.external_id,
                o.order_placed_at,
                o.lifecycle_status,
                o.payment_method_raw,
                o.billing_name,
                o.billing_company,
                o.billing_address_line1,
                o.billing_address_line2,
                o.billing_address_line3,
                o.billing_city,
                o.billing_postcode,
                o.billing_country,
                o.billing_email,
                o.billing_telephone,
                o.delivery_name,
                o.delivery_company,
                o.delivery_address_line1,
                o.delivery_address_line2,
                o.delivery_address_line3,
                o.delivery_city,
                o.delivery_postcode,
                o.delivery_country,
                o.shipping_method,
                o.shipping_total_net::text AS shipping_total_net,
                o.shipping_vat_rate::text AS shipping_vat_rate,
                o.comments,
                o.transaction_id,
                COALESCE(
                    (
                        SELECT jsonb_agg(
                            jsonb_build_object(
                                'sku', p.sku,
                                'title', p.title,
                                'quantity', p.quantity,
                                'price', p.price::text,
                                'price_vat', p.price_vat::text,
                                'vat_rate', p.vat_rate::text
                            )
                            -- intentional: variations of one product share external_id, so the tie-break keeps the order deterministic
                            ORDER BY p.external_id, p.variation_hash NULLS FIRST, p.id
                        )
                        FROM shopwired.order_products_resolved p
                        WHERE p.order_external_id = o.external_id
                    ),
                    '[]'::jsonb
                ) AS lines
            FROM shopwired.orders_deduplicated o
        SQL);

        $this->revokeSupabaseAccess();
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS quickbooks CASCADE');
    }

    /**
     * intentional: service_role bypasses RLS, so only the REVOKE keeps accounting state out of the Data API (COR-280 D22).
     * Skipping current_user keeps the migrating role's own access.
     */
    private function revokeSupabaseAccess(): void
    {
        $roles = "ARRAY['PUBLIC', '" . \implode("', '", self::SUPABASE_ROLES) . "']";

        DB::statement(<<<SQL
            DO \$\$
            DECLARE
                grantee text;
                target  text;
            BEGIN
                FOREACH grantee IN ARRAY {$roles} LOOP
                    IF grantee = 'PUBLIC' THEN
                        target := 'PUBLIC';
                    ELSIF grantee <> current_user AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = grantee) THEN
                        target := quote_ident(grantee);
                    ELSE
                        CONTINUE;
                    END IF;

                    EXECUTE format('REVOKE ALL ON SCHEMA quickbooks FROM %s', target);
                    EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA quickbooks FROM %s', target);
                    EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA quickbooks FROM %s', target);
                    EXECUTE format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA quickbooks FROM %s', target);
                    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA quickbooks REVOKE ALL ON TABLES FROM %s', target);
                    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA quickbooks REVOKE ALL ON SEQUENCES FROM %s', target);
                    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA quickbooks REVOKE ALL ON FUNCTIONS FROM %s', target);
                END LOOP;
            END
            \$\$
        SQL);
    }
};
