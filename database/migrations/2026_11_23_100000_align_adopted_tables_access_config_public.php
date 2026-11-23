<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings alz-admin's adopted tables to production's structure wherever they differ; a no-op on production.
 *
 * intentional: every guard reads catalog identity, never definition text, which renders by search_path.
 *
 * @see database/structure/adopted_tables.sql
 */
return new class extends Migration {
    private const string NO_ACTION = 'a';

    private const string CASCADE = 'c';

    private const string SET_NULL = 'n';

    private const array DELETE_CLAUSES = [
        self::NO_ACTION => '',
        self::CASCADE => ' ON DELETE CASCADE',
        self::SET_NULL => ' ON DELETE SET NULL',
    ];

    private const array AUTH_FOREIGN_KEYS = [
        'access.department_permissions' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION],
        'access.departments' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION],
        'access.permissions' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION],
        'access.role_permissions' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION],
        'access.roles' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION],
        'access.user_departments' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION, 'user_id' => self::CASCADE],
        'access.user_permissions' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION, 'user_id' => self::CASCADE],
        'access.user_roles' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION, 'user_id' => self::CASCADE],
        'config.dashboard' => ['added_by' => self::SET_NULL, 'updated_by' => self::SET_NULL],
        'public.profiles' => ['id' => self::CASCADE],
        'public.user_api_keys' => ['added_by' => self::NO_ACTION, 'updated_by' => self::NO_ACTION, 'user_id' => self::CASCADE],
    ];

    private const array ROLES_POLICIES = [
        'All authenticated users can view roles' => 'FOR SELECT TO authenticated USING (true)',
        'Only admins can create roles' => 'FOR INSERT TO authenticated WITH CHECK (utils.is_admin(auth.uid()))',
        'Only admins can modify roles' => 'FOR UPDATE TO authenticated USING (utils.is_admin(auth.uid())) WITH CHECK (utils.is_admin(auth.uid()))',
        'Only admins can delete roles' => 'FOR DELETE TO authenticated USING (utils.is_admin(auth.uid()))',
    ];

    private const array UUID_DEFAULT_TABLES = ['config.dashboard', 'public.auth_allowed_domains', 'public.auth_allowed_emails'];

    private const array MAINTAIN_REVOKES = [
        'access.user_roles' => ['service_role'],
        'config.dashboard' => ['service_role'],
        'public.auth_allowed_domains' => ['anon', 'authenticated', 'service_role'],
        'public.auth_allowed_emails' => ['anon', 'authenticated', 'service_role'],
        'public.profiles' => ['anon', 'authenticated', 'service_role'],
        'public.system_cache' => ['anon', 'authenticated', 'service_role'],
        'public.user_api_keys' => ['anon', 'authenticated', 'service_role'],
    ];

    public function up(): void
    {
        $this->addAuthForeignKeys();
        $this->createRolesPolicies();
        $this->openDashboardPoliciesToPublic();
        $this->setUuidDefaults();
        $this->revokeMaintain();
    }

    /** intentional: on production and local these objects predate this migration, so a rollback removes none of them. */
    public function down(): void {}

    private function addAuthForeignKeys(): void
    {
        foreach (self::AUTH_FOREIGN_KEYS as $table => $columns) {
            foreach ($columns as $column => $onDelete) {
                $name = \explode('.', $table)[1] . "_{$column}_fkey";

                if ($this->authForeignKeyExists($table, $name, $column, $onDelete)) {
                    continue;
                }

                DB::statement(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES auth.users (id)"
                    . self::DELETE_CLAUSES[$onDelete],
                );
            }
        }
    }

    private function authForeignKeyExists(string $table, string $name, string $column, string $onDelete): bool
    {
        return DB::selectOne(<<<'SQL'
            SELECT 1
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND c.conkey = ARRAY[a.attnum]
            WHERE c.contype = 'f'
                AND c.conrelid = CAST(? AS regclass)
                AND c.confrelid = CAST('auth.users' AS regclass)
                AND c.conname = ?
                AND a.attname = ?
                AND c.confdeltype = ?
        SQL, [$table, $name, $column, $onDelete]) !== null;
    }

    private function createRolesPolicies(): void
    {
        foreach (self::ROLES_POLICIES as $name => $definition) {
            $exists = DB::selectOne(
                "SELECT 1 FROM pg_policy WHERE polrelid = CAST('access.roles' AS regclass) AND polname = ?",
                [$name],
            ) !== null;

            if (! $exists) {
                DB::statement("CREATE POLICY \"{$name}\" ON access.roles AS PERMISSIVE {$definition}");
            }
        }
    }

    private function openDashboardPoliciesToPublic(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                pol record;
            BEGIN
                FOR pol IN
                    SELECT polname FROM pg_policy
                    WHERE polrelid = 'config.dashboard'::regclass
                        AND polname IN (
                            'Admins and managers can modify dashboard configurations',
                            'Authenticated users can view dashboard configurations'
                        )
                        AND polroles <> ARRAY[0::oid]
                LOOP
                    EXECUTE format('ALTER POLICY %I ON config.dashboard TO PUBLIC', pol.polname);
                END LOOP;
            END
            $$
        SQL);
    }

    private function setUuidDefaults(): void
    {
        foreach (self::UUID_DEFAULT_TABLES as $table) {
            $usesUuidOssp = DB::selectOne(<<<'SQL'
                SELECT 1
                FROM pg_attrdef d
                JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
                JOIN pg_depend dep ON dep.classid = CAST('pg_attrdef' AS regclass) AND dep.objid = d.oid
                JOIN pg_proc p ON dep.refclassid = CAST('pg_proc' AS regclass) AND p.oid = dep.refobjid
                WHERE d.adrelid = CAST(? AS regclass)
                    AND a.attname = 'id'
                    AND p.proname = 'uuid_generate_v4'
            SQL, [$table]) !== null;

            if (! $usesUuidOssp) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT extensions.uuid_generate_v4()");
            }
        }
    }

    private function revokeMaintain(): void
    {
        foreach (self::MAINTAIN_REVOKES as $table => $grantees) {
            foreach ($grantees as $grantee) {
                $granted = DB::selectOne(<<<'SQL'
                    SELECT 1
                    FROM pg_class c, aclexplode(c.relacl) x
                    WHERE c.oid = CAST(? AS regclass)
                        AND x.grantee = CAST(CAST(? AS regrole) AS oid)
                        AND x.privilege_type = 'MAINTAIN'
                SQL, [$table, $grantee]) !== null;

                if ($granted) {
                    DB::statement("REVOKE MAINTAIN ON TABLE {$table} FROM {$grantee}");
                }
            }
        }
    }
};
