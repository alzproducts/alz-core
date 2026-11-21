<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\SupabaseLookAlikeCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * No migration creates a Supabase-owned schema, object or role (AC6).
 */
#[CoversNothing]
#[Group('repo-static')]
final class SupabaseLookAlikeTest extends TestCase
{
    private const string PLANTED = 'database/migrations/2099_01_01_000000_planted.php';

    #[Test]
    public function no_migration_creates_a_supabase_owned_object(): void
    {
        $root = \base_path();

        $this->assertSame([], SupabaseLookAlikeCheck::violations(TrackedFiles::list($root), TrackedFiles::reader($root)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plantedLookAlikes(): iterable
    {
        yield 'schema' => ['DB::statement("CREATE SCHEMA auth");', 'auth schema'];
        yield 'schema if not exists, quoted' => ['create schema if not exists "auth"', 'auth schema'];
        yield 'table' => ['CREATE TABLE auth.users (id uuid)', 'auth object'];
        yield 'table if not exists, split across lines' => ["CREATE TABLE\n    IF NOT EXISTS\n    auth.mfa_factors (id uuid)", 'auth object'];
        yield 'quoted schema table' => ['CREATE TABLE "auth".users (id uuid)', 'auth object'];
        yield 'type' => ["CREATE TYPE auth.factor_status AS ENUM ('verified')", 'auth object'];
        yield 'function' => ['CREATE FUNCTION auth.uid() RETURNS uuid', 'auth object'];
        yield 'or replace function, lower case' => ['create or replace function auth.jwt() returns jsonb', 'auth object'];
        yield 'role' => ['CREATE ROLE anon NOLOGIN', 'Supabase role'];
        yield 'role, quoted' => ['CREATE ROLE "service_role"', 'Supabase role'];
        yield 'user' => ['create user supabase_auth_admin', 'Supabase role'];
        yield 'role split across lines' => ["CREATE ROLE\n    authenticated NOLOGIN", 'Supabase role'];
        yield 'schema builder create' => ["Schema::create('auth.users', static function (): void {});", 'auth table via the schema builder'];
        yield 'schema builder table' => ['Schema::table( "auth.users", static function (): void {});', 'auth table via the schema builder'];
    }

    #[Test]
    #[DataProvider('plantedLookAlikes')]
    public function a_planted_look_alike_fails_the_check(string $statement, string $label): void
    {
        $this->assertSame(
            ["{$label} created in " . self::PLANTED],
            SupabaseLookAlikeCheck::violations([self::PLANTED], static fn(string $path): string => "<?php\n{$statement}\n"),
        );
    }

    #[Test]
    public function names_that_only_start_with_auth_or_a_role_pass(): void
    {
        $contents = <<<'PHP'
            CREATE TABLE public.auth_allowed_emails (email text);
            CREATE SCHEMA authz;
            CREATE FUNCTION utils.auth_role() RETURNS text;
            CREATE ROLE anonymous_reader;
            GRANT USAGE ON SCHEMA auth TO anon, authenticated, service_role;
            CREATE POLICY p ON access.profiles USING (auth.uid() = user_id);
            Schema::create('public.auth_allowed_domains', static function (): void {});
            PHP;

        $this->assertSame([], SupabaseLookAlikeCheck::violations([self::PLANTED], static fn(string $path): string => $contents));
    }

    #[Test]
    public function a_look_alike_outside_the_migrations_is_not_scanned(): void
    {
        $this->assertSame(
            [],
            SupabaseLookAlikeCheck::violations([self::PLANTED, 'tests/Fixture.php'], static fn(string $path): string => $path === self::PLANTED ? '' : 'CREATE SCHEMA auth'),
        );
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(['the file listing is empty'], SupabaseLookAlikeCheck::violations([], static fn(string $path): string => ''));
    }

    #[Test]
    public function a_listing_without_migrations_fails_closed(): void
    {
        $this->assertSame(['no migration in the listing'], SupabaseLookAlikeCheck::violations(['README.md'], static fn(string $path): string => ''));
    }
}
