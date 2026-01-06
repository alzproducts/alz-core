<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: public helper functions (standalone)
 *
 * Creates trigger functions for audit fields and timestamps.
 * These functions use auth.uid() from the mock auth schema.
 *
 * NOTE: handle_new_user() is NOT included here - it depends on the profiles
 * table and is created in a later migration.
 *
 * Functions created:
 *   - set_audit_fields_insert() - sets created_at, updated_at, added_by, updated_by
 *   - set_audit_fields_update() - sets updated_at, updated_by
 *   - set_added_by() - sets added_by to auth.uid()
 *   - set_updated_by() - sets updated_by to auth.uid()
 *   - set_timestamps() - sets created_at, updated_at
 *   - update_timestamp() - sets updated_at
 *   - increment_version() - increments version column
 *
 * In production, these functions already exist - migration skips creation.
 * In CI/testing, creates the functions from scratch.
 *
 * Source: /Users/tom/WebstormProjects/alz-admin/supabase/migrations/
 *   - 00000000000000_initial_schema.sql (lines 126-195)
 */
return new class extends Migration {
    public function up(): void
    {
        $this->createSetAuditFieldsInsert();
        $this->createSetAuditFieldsUpdate();
        $this->createSetAddedBy();
        $this->createSetUpdatedBy();
        $this->createSetTimestamps();
        $this->createUpdateTimestamp();
        $this->createIncrementVersion();
    }

    public function down(): void
    {
        // Note: Will fail if triggers depend on these functions.
        // For adoption migrations, down() is rarely called.
        DB::statement('DROP FUNCTION IF EXISTS public.increment_version()');
        DB::statement('DROP FUNCTION IF EXISTS public.update_timestamp()');
        DB::statement('DROP FUNCTION IF EXISTS public.set_timestamps()');
        DB::statement('DROP FUNCTION IF EXISTS public.set_updated_by()');
        DB::statement('DROP FUNCTION IF EXISTS public.set_added_by()');
        DB::statement('DROP FUNCTION IF EXISTS public.set_audit_fields_update()');
        DB::statement('DROP FUNCTION IF EXISTS public.set_audit_fields_insert()');
    }

    private function createSetAuditFieldsInsert(): void
    {
        if ($this->functionExists('set_audit_fields_insert')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.set_audit_fields_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.created_at = NOW();
                NEW.updated_at = NOW();
                NEW.added_by = auth.uid();
                NEW.updated_by = auth.uid();
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function createSetAuditFieldsUpdate(): void
    {
        if ($this->functionExists('set_audit_fields_update')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.set_audit_fields_update()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.updated_at = NOW();
                NEW.updated_by = auth.uid();
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function createSetAddedBy(): void
    {
        if ($this->functionExists('set_added_by')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.set_added_by()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.added_by = auth.uid();
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function createSetUpdatedBy(): void
    {
        if ($this->functionExists('set_updated_by')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.set_updated_by()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.updated_by = auth.uid();
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function createSetTimestamps(): void
    {
        if ($this->functionExists('set_timestamps')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.set_timestamps()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.created_at = NOW();
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function createUpdateTimestamp(): void
    {
        if ($this->functionExists('update_timestamp')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.update_timestamp()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function createIncrementVersion(): void
    {
        if ($this->functionExists('increment_version')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.increment_version()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                NEW.version = OLD.version + 1;
                RETURN NEW;
            END;
            $function$
        SQL);
    }

    private function functionExists(string $functionName): bool
    {
        $result = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.routines
                WHERE routine_schema = 'public'
                AND routine_name = ?
            ) as exists",
            [$functionName],
        );

        return $result->exists;
    }
};
