<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: user_role enum type
 *
 * Creates the 'user_role' enum type used by profiles.role column.
 * Values: unauthorized, guest, standard, admin, superadmin
 *
 * In production, this type already exists - migration skips creation.
 * In CI/testing, creates the type from scratch.
 *
 * Source: ${FRONTEND_APP}/supabase/migrations/
 *   - 00000000000000_initial_schema.sql (line 1)
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if enum type already exists (production case)
        $typeExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') as exists",
        );

        if ($typeExists->exists) {
            // Type exists in production - adoption complete
            return;
        }

        // CI/Testing: Create enum type from scratch
        DB::statement("CREATE TYPE public.user_role AS ENUM ('unauthorized', 'guest', 'standard', 'admin', 'superadmin')");
    }

    public function down(): void
    {
        // Only drop if not used by any table
        $isUsed = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE udt_name = 'user_role'
            ) as exists",
        );

        if (! $isUsed->exists) {
            DB::statement('DROP TYPE IF EXISTS public.user_role');
        }
    }
};
