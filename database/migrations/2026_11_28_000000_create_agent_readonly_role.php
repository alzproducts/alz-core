<?php

declare(strict_types=1);

use App\Infrastructure\Database\AgentRead\AgentReadGrantSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the Agent database login without LOGIN: a human enables login and sets its password in production
 * (docs/guides/agent-production-reads.md). Its grants come from AgentReadGrantSync after every migrate, never from here.
 *
 * Re-runnable. Forward-only.
 */
return new class extends Migration {
    public function up(): void
    {
        $role = AgentReadGrantSync::ROLE;

        // intentional: an existing role is left as it is, so a re-run never undoes the human's LOGIN step.
        DB::statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT CONNECTION LIMIT 5;
                END IF;
            END
            $$
        SQL);

        // intentional: session defaults only, which the role can SET back; the grants are the boundary.
        DB::statement("ALTER ROLE {$role} SET default_transaction_read_only = on");
        DB::statement("ALTER ROLE {$role} SET statement_timeout = '30s'");
        DB::statement("ALTER ROLE {$role} SET pgaudit.log = 'read'");
    }

    public function down(): void
    {
        throw new LogicException('forward-only');
    }
};
