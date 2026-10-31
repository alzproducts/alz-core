<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-product JSON fragments for GET /api/products, keyed by a content fingerprint of their inputs.
 *
 * `body` is text, not jsonb: jsonb reorders keys and reformats numbers. There is no foreign key;
 * the drift check deletes rows for products that no longer exist.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE catalog.product_list_fragments (
                product_id  uuid        NOT NULL,
                shape       text        NOT NULL,
                fingerprint char(32)    NOT NULL,
                body        text        NOT NULL,
                built_at    timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (product_id, shape)
            )
        SQL);

        // intentional: the catalog default privileges grant DML to authenticated; a client able to write here could forge a cached body.
        DB::statement('REVOKE ALL ON catalog.product_list_fragments FROM authenticated, anon');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS catalog.product_list_fragments');
    }
};
