<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class RemovePublicSecurityDefinerGrantsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const string MIGRATION = 'database/migrations/2026_11_27_200000_remove_public_security_definer_grants.php';

    private const string FUNCTION = 'public.rls_auto_enable()';

    private const string FIXTURE_OWNER = 'cor393_fixture_owner';

    private const array API_ROLES = ['anon', 'authenticated', 'service_role'];

    /** The issue's read-only Removal check query, verbatim. */
    private const string REMOVAL_CHECK = <<<'SQL'
        WITH pub_schema AS (
          SELECT n.oid, n.nspname
          FROM pg_namespace n, aclexplode(coalesce(n.nspacl, acldefault('n', n.nspowner))) a
          WHERE a.grantee = 0 AND a.privilege_type = 'USAGE'
            AND n.nspname NOT IN ('pg_catalog', 'information_schema')
        ),
        reach AS (
          SELECT p.oid, s.nspname, p.proname, pg_get_userbyid(p.proowner)::text AS owner,
                 (SELECT d.refobjid FROM pg_depend d
                  WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid
                    AND d.refclassid = 'pg_extension'::regclass AND d.deptype = 'e') AS ext_oid
          FROM pg_proc p JOIN pub_schema s ON s.oid = p.pronamespace
          WHERE p.prosecdef
            AND EXISTS (SELECT 1 FROM aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                        WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE')
        ),
        ext AS (
          SELECT DISTINCT ext_oid FROM reach WHERE ext_oid IS NOT NULL
        ),
        members AS (
          SELECT d.refobjid AS ext_oid, d.classid, d.objid
          FROM pg_depend d JOIN ext ON ext.ext_oid = d.refobjid
          WHERE d.refclassid = 'pg_extension'::regclass AND d.deptype = 'e'
        )
        SELECT 'extension' AS kind,
               e.extname AS name,
               (SELECT string_agg(DISTINCT r.owner, ', ') FROM reach r WHERE r.ext_oid = e.oid) AS owners,
               (SELECT count(*) FROM reach r WHERE r.ext_oid = e.oid) AS reachable_functions,
               (SELECT coalesce(sum(st.n_live_tup), 0) FROM members m JOIN pg_stat_all_tables st ON st.relid = m.objid
                WHERE m.ext_oid = e.oid AND m.classid = 'pg_class'::regclass) AS live_rows_in_its_tables,
               (SELECT count(*) FROM pg_seclabel sl WHERE sl.provider = e.extname
                  AND NOT EXISTS (SELECT 1 FROM members m WHERE m.ext_oid = e.oid AND m.objid = sl.objoid)) AS labels_on_other_objects,
               (SELECT coalesce(string_agg(e2.extname, ', '), '') FROM pg_depend d JOIN pg_extension e2 ON e2.oid = d.objid
                WHERE d.classid = 'pg_extension'::regclass AND d.refobjid = e.oid) AS dependent_extensions
        FROM pg_extension e JOIN ext ON ext.ext_oid = e.oid
        UNION ALL
        SELECT 'function', r.nspname || '.' || r.proname || '(' || pg_get_function_identity_arguments(r.oid) || ')',
               r.owner, 1, NULL, NULL, NULL
        FROM reach r WHERE r.ext_oid IS NULL
        ORDER BY 1, 2
        SQL;

    #[Test]
    public function it_leaves_the_removal_check_empty_when_both_objects_exist(): void
    {
        DB::statement('CREATE EXTENSION pgsodium');
        self::createFunction();

        $names = \array_column(DB::select(self::REMOVAL_CHECK), 'name');
        self::assertContains('pgsodium', $names);
        self::assertContains(self::FUNCTION, $names);

        self::runMigration();

        self::assertSame([], DB::select(self::REMOVAL_CHECK));
    }

    #[Test]
    public function it_keeps_the_function_grants_to_the_api_roles(): void
    {
        self::createFunction();
        $before = self::apiRoleGrants();
        self::assertSame(self::API_ROLES, \array_keys($before), 'The schema default grants to the API roles are missing');

        self::runMigration();

        self::assertSame($before, self::apiRoleGrants());
    }

    #[Test]
    public function it_fails_with_a_dependency_error_and_keeps_the_extension_when_an_object_depends_on_it(): void
    {
        DB::statement('CREATE EXTENSION pgsodium');
        DB::statement('CREATE TABLE public.cor393_fixture_keys (status pgsodium.key_status)');

        $error = self::runMigrationExpectingFailure();

        self::assertSame('2BP01', $error->getCode());
        self::assertStringContainsString('cannot drop extension pgsodium because other objects depend on it', $error->getMessage());
        self::assertTrue(self::extensionInstalled());
    }

    #[Test]
    public function it_fails_naming_the_function_when_its_public_execute_survives_the_revoke(): void
    {
        self::createFunction();
        self::transferFunctionToRolePostgresCannotActAs();
        self::assertTrue(self::publicCanExecute());

        $error = self::runMigrationExpectingFailure();

        self::assertStringContainsString('PUBLIC can still execute ' . self::FUNCTION, $error->getMessage());
        self::assertTrue(self::publicCanExecute());
    }

    #[Test]
    public function the_event_trigger_still_enables_row_level_security_after_the_migration(): void
    {
        self::createFunction();
        DB::statement(
            "CREATE EVENT TRIGGER cor393_fixture_rls ON ddl_command_end WHEN TAG IN ('CREATE TABLE') EXECUTE FUNCTION " . self::FUNCTION,
        );

        self::runMigration();
        DB::statement('CREATE TABLE public.cor393_fixture_new (id int)');

        self::assertFalse(self::publicCanExecute());
        $table = DB::selectOne("SELECT relrowsecurity FROM pg_class WHERE oid = 'public.cor393_fixture_new'::regclass");
        self::assertNotNull($table);
        self::assertTrue($table->relrowsecurity);
    }

    private static function createFunction(): void
    {
        DB::statement(<<<'SQL'
            CREATE FUNCTION public.rls_auto_enable()
            RETURNS event_trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            DECLARE
                cmd record;
            BEGIN
                FOR cmd IN
                    SELECT * FROM pg_event_trigger_ddl_commands()
                    WHERE command_tag = 'CREATE TABLE' AND object_type = 'table'
                LOOP
                    EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', cmd.object_identity);
                END LOOP;
            END
            $$
            SQL);
    }

    /**
     * postgres keeps ADMIN on a role it creates but drops its own SET membership, so it can neither
     * act as the new owner nor revoke the owner's PUBLIC grant: the REVOKE only warns.
     */
    private static function transferFunctionToRolePostgresCannotActAs(): void
    {
        $owner = self::FIXTURE_OWNER;
        DB::statement("CREATE ROLE {$owner} NOLOGIN");
        DB::statement("GRANT {$owner} TO postgres WITH SET TRUE");
        DB::statement("GRANT CREATE ON SCHEMA public TO {$owner}");
        DB::statement('ALTER FUNCTION ' . self::FUNCTION . " OWNER TO {$owner}");
        DB::statement("REVOKE {$owner} FROM postgres");
    }

    private static function runMigration(): void
    {
        $migration = require \base_path(self::MIGRATION);
        self::assertInstanceOf(Migration::class, $migration);
        self::assertTrue(\method_exists($migration, 'up'));
        $migration->up();
    }

    private static function runMigrationExpectingFailure(): QueryException
    {
        try {
            DB::transaction(static fn() => self::runMigration());
        } catch (QueryException $error) {
            return $error;
        }

        self::fail('The migration did not fail');
    }

    /**
     * @return array<string, string>
     */
    private static function apiRoleGrants(): array
    {
        $grants = [];
        foreach (DB::select(
            "SELECT a.grantee::regrole::text AS grantee, a.privilege_type, a.grantor::regrole::text AS grantor
             FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
             WHERE p.oid = to_regprocedure(?) AND a.grantee <> 0 AND a.grantee::regrole::text = ANY (?)
             ORDER BY 1",
            [self::FUNCTION, '{' . \implode(',', self::API_ROLES) . '}'],
        ) as $grant) {
            $grants[$grant->grantee] = "{$grant->privilege_type} by {$grant->grantor}";
        }

        return $grants;
    }

    private static function publicCanExecute(): bool
    {
        $row = DB::selectOne(
            "SELECT EXISTS (
                 SELECT 1 FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                 WHERE p.oid = to_regprocedure(?) AND a.grantee = 0 AND a.privilege_type = 'EXECUTE'
             ) AS granted",
            [self::FUNCTION],
        );
        self::assertNotNull($row);

        return (bool) $row->granted;
    }

    private static function extensionInstalled(): bool
    {
        return DB::table('pg_extension')->where('extname', 'pgsodium')->exists();
    }
}
