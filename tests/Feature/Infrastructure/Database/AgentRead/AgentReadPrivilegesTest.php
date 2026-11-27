<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Database\AgentRead;

use App\Infrastructure\Database\AgentRead\AgentReadAllowlist;
use App\Infrastructure\Database\AgentRead\AgentReadAllowlistRules;
use App\Infrastructure\Database\AgentRead\AgentReadGrantSync;
use App\Infrastructure\Database\AgentRead\Listeners\SyncAgentReadGrantsListener;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Agent database login's privileges on the migrated Supabase baseline, judged as the role itself would see them.
 */
#[CoversClass(AgentReadGrantSync::class)]
#[CoversClass(SyncAgentReadGrantsListener::class)]
final class AgentReadPrivilegesTest extends TestCase
{
    use DatabaseTransactions;
    use WithConsoleEvents;

    /** @var list<string> */
    protected array $connectionsToTransact = ['pgsql'];

    private const string ROLE = AgentReadGrantSync::ROLE;

    private const string FIXTURES = 'tests/Fixtures/AgentRead';

    private const string RECREATED_VIEW = 'catalog.products_with_changed_ratings';

    private const string SYSTEM_SCHEMAS = "n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_toast%' AND n.nspname NOT LIKE 'pg\\_temp%'";

    #[Test]
    public function the_role_is_a_nologin_role_with_no_elevated_attributes_or_memberships(): void
    {
        $role = $this->db()->selectOne(
            'SELECT rolcanlogin, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls, rolconnlimit,
                    (SELECT count(*) FROM pg_auth_members m WHERE m.member = r.oid) AS memberships
             FROM pg_roles r WHERE rolname = ?',
            [self::ROLE],
        );

        self::assertNotNull($role, 'The Agent database login does not exist');
        self::assertFalse($role->rolcanlogin);
        self::assertFalse($role->rolsuper);
        self::assertFalse($role->rolcreaterole);
        self::assertFalse($role->rolcreatedb);
        self::assertFalse($role->rolreplication);
        self::assertFalse($role->rolbypassrls);
        self::assertSame(0, $role->memberships);
        self::assertSame(5, $role->rolconnlimit);
    }

    #[Test]
    public function the_role_stores_read_only_timeout_and_audit_defaults(): void
    {
        $settings = $this->db()->selectOne(
            "SELECT array_to_string(rolconfig, '|') AS config FROM pg_roles WHERE rolname = ?",
            [self::ROLE],
        );
        self::assertNotNull($settings);
        $config = \explode('|', (string) $settings->config);

        self::assertContains('default_transaction_read_only=on', $config);
        self::assertContains('pgaudit.log=read', $config);
        self::assertContains('statement_timeout=30s', $config);
    }

    #[Test]
    public function the_role_can_use_exactly_the_read_allowlist_and_nothing_else(): void
    {
        self::assertSame([], $this->privilegeViolations());
    }

    #[Test]
    public function a_new_table_and_a_new_column_stay_unreadable_after_migrate(): void
    {
        $this->migrateFixture('new-objects');

        $table = $this->db()->selectOne(
            "SELECT has_table_privilege(?, 'shopwired.cor385_fixture_new', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') AS any_table,
                    has_any_column_privilege(?, 'shopwired.cor385_fixture_new', 'SELECT,INSERT,UPDATE,REFERENCES') AS any_column,
                    has_column_privilege(?, 'shopwired.brands', 'cor385_fixture_col', 'SELECT') AS new_column",
            [self::ROLE, self::ROLE, self::ROLE],
        );
        self::assertNotNull($table);
        self::assertFalse($table->any_table);
        self::assertFalse($table->any_column);
        self::assertFalse($table->new_column);
        self::assertSame([], $this->privilegeViolations());
    }

    #[Test]
    public function an_executable_security_definer_function_fails_the_privilege_test_by_name(): void
    {
        $this->migrateFixture('security-definer');

        self::assertContains(
            'security definer function shopwired.cor385_fixture_definer() EXECUTE',
            $this->privilegeViolations(),
        );
    }

    #[Test]
    public function a_dropped_and_recreated_view_regains_its_allowlisted_columns_after_migrate(): void
    {
        $expected = AgentReadAllowlist::committed()->relations[self::RECREATED_VIEW];
        \sort($expected);

        $this->migrateFixture('recreated-view');

        self::assertSame($expected, $this->readableColumnsOf(self::RECREATED_VIEW));
        self::assertSame([], $this->privilegeViolations());
    }

    #[Test]
    public function a_migrate_with_nothing_pending_undoes_hand_made_grant_changes(): void
    {
        $role = self::ROLE;
        $this->db()->statement("REVOKE SELECT (title) ON shopwired.products FROM {$role}");
        $this->db()->statement("GRANT SELECT (email) ON shopwired.customers TO {$role}");
        self::assertNotSame([], $this->privilegeViolations());

        Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);

        self::assertStringContainsString('Nothing to migrate', Artisan::output());
        self::assertSame([], $this->privilegeViolations());
    }

    #[Test]
    public function a_pretend_migrate_with_nothing_pending_changes_no_grants(): void
    {
        $role = self::ROLE;
        $this->db()->statement("GRANT SELECT (email) ON shopwired.customers TO {$role}");

        Artisan::call('migrate', ['--database' => 'pgsql', '--pretend' => true, '--force' => true]);

        self::assertContains('readable but not allowlisted: shopwired.customers.email', $this->privilegeViolations());

        Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);

        self::assertSame([], $this->privilegeViolations());
    }

    #[Test]
    public function the_committed_allowlist_passes_its_rules_against_the_migrated_schema(): void
    {
        $allowlist = AgentReadAllowlist::committed();
        $types = [];
        foreach ($this->db()->select(
            "SELECT n.nspname || '.' || c.relname || '.' || a.attname AS name, t.typname AS type
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
             JOIN pg_type t ON t.oid = a.atttypid",
        ) as $row) {
            $types[(string) $row->name] = (string) $row->type;
        }

        self::assertSame([], (new AgentReadAllowlistRules())->violations($allowlist, $types));
    }

    /**
     * Every way the role's usable privileges differ from the Read allowlist: the committed audit query run as the
     * role, plus the effective readable set compared both ways.
     *
     * @return list<string>
     */
    private function privilegeViolations(): array
    {
        $violations = [];
        foreach ($this->auditAsRole() as $row) {
            $violations[] = "{$row->kind} {$row->object} {$row->privilege}";
        }

        $allowed = AgentReadAllowlist::committed()->columns();
        $readable = $this->readableColumns();
        foreach (\array_diff($readable, $allowed) as $column) {
            $violations[] = "readable but not allowlisted: {$column}";
        }
        foreach (\array_diff($allowed, $readable) as $column) {
            $violations[] = "allowlisted but not readable: {$column}";
        }

        return [...$violations, ...$this->writeOrCreatePrivileges()];
    }

    /**
     * @return list<object{kind: string, object: string, privilege: string}>
     */
    private function auditAsRole(): array
    {
        $db = $this->db();
        $db->statement('GRANT ' . self::ROLE . ' TO CURRENT_USER WITH SET TRUE');
        $db->statement('SET LOCAL ROLE ' . self::ROLE);

        try {
            /** @var list<object{kind: string, object: string, privilege: string}> */
            return $db->select((string) \file_get_contents(\database_path('agent-read/privilege-audit.sql')));
        } finally {
            $db->statement('RESET ROLE');
        }
    }

    /**
     * @return list<string> schema.relation.column the role can SELECT, schema USAGE included, across every user schema
     */
    private function readableColumns(?string $relation = null): array
    {
        $columns = \array_map(static fn(object $row): string => (string) $row->name, $this->db()->select(
            "SELECT n.nspname || '.' || c.relname || '.' || a.attname AS name
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
             WHERE " . self::SYSTEM_SCHEMAS . " AND c.relkind IN ('r', 'v', 'm', 'p', 'f')
               AND (CAST(? AS text) IS NULL OR c.oid = CAST(? AS text)::regclass)
               AND has_schema_privilege(?, n.oid, 'USAGE') AND has_column_privilege(?, c.oid, a.attnum, 'SELECT')
             ORDER BY 1",
            [$relation, $relation, self::ROLE, self::ROLE],
        ));

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function readableColumnsOf(string $relation): array
    {
        return \array_map(
            static fn(string $qualified): string => \mb_substr($qualified, \mb_strlen($relation) + 1),
            $this->readableColumns($relation),
        );
    }

    /**
     * @return list<string> effective writes, sequence access and CREATE, direct or through PUBLIC
     */
    private function writeOrCreatePrivileges(): array
    {
        $role = [self::ROLE, self::ROLE, self::ROLE];

        return \array_map(static fn(object $row): string => (string) $row->finding, $this->db()->select(
            "SELECT 'write on ' || c.oid::regclass::text AS finding
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE " . self::SYSTEM_SCHEMAS . " AND c.relkind IN ('r', 'v', 'm', 'p', 'f')
               AND has_schema_privilege(?, n.oid, 'USAGE')
               AND (has_table_privilege(?, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                    OR has_any_column_privilege(?, c.oid, 'INSERT,UPDATE,REFERENCES'))
             UNION ALL
             SELECT 'sequence ' || c.oid::regclass::text
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE " . self::SYSTEM_SCHEMAS . " AND c.relkind = 'S'
               AND has_schema_privilege(?, n.oid, 'USAGE')
               AND CASE WHEN c.relkind = 'S' THEN has_sequence_privilege(?, c.oid, 'SELECT,USAGE,UPDATE') ELSE false END
             UNION ALL
             SELECT 'CREATE on schema ' || n.nspname FROM pg_namespace n
             WHERE n.oid <> pg_my_temp_schema() AND has_schema_privilege(?, n.oid, 'CREATE')
             UNION ALL
             SELECT 'CREATE on database' WHERE has_database_privilege(?, current_database(), 'CREATE')
             ORDER BY 1",
            [...$role, self::ROLE, self::ROLE, self::ROLE, self::ROLE],
        ));
    }

    private function migrateFixture(string $directory): void
    {
        $exit = Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => \base_path(self::FIXTURES . "/{$directory}"),
            '--realpath' => true,
            '--force' => true,
        ]);

        self::assertSame(0, $exit, Artisan::output());
    }

    private function db(): Connection
    {
        $db = DB::connection('pgsql');
        self::assertInstanceOf(Connection::class, $db);

        return $db;
    }
}
