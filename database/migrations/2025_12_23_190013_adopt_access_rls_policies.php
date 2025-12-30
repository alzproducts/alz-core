<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adopts all RLS policies for access schema tables.
 *
 * Creates 31 policies across 8 tables:
 * - access.roles (2)
 * - access.permissions (3)
 * - access.departments (5)
 * - access.user_roles (3)
 * - access.user_departments (4)
 * - access.user_permissions (5)
 * - access.role_permissions (4)
 * - access.department_permissions (5)
 *
 * All policies are idempotent - if a policy already exists (e.g., from Supabase),
 * it will be skipped without error.
 *
 * @see /Users/tom/WebstormProjects/alz-admin/supabase/migrations/00000000000000_initial_schema.sql
 */
return new class extends Migration {
    public function up(): void
    {
        // access.roles policies (2)
        $this->createPolicyIfNotExists(
            'access.roles',
            'Roles are modifiable by admins only',
            <<<'SQL'
                CREATE POLICY "Roles are modifiable by admins only"
                ON access.roles
                AS PERMISSIVE
                FOR ALL
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.roles',
            'Roles are viewable by authenticated users',
            <<<'SQL'
                CREATE POLICY "Roles are viewable by authenticated users"
                ON access.roles
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING ((is_active = true))
            SQL,
        );

        // access.permissions policies (3)
        $this->createPolicyIfNotExists(
            'access.permissions',
            'Only non-system permissions can be deleted by admins',
            <<<'SQL'
                CREATE POLICY "Only non-system permissions can be deleted by admins"
                ON access.permissions
                AS PERMISSIVE
                FOR DELETE
                TO authenticated
                USING (((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))) AND (is_system = false)))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.permissions',
            'Permissions are modifiable by admins only',
            <<<'SQL'
                CREATE POLICY "Permissions are modifiable by admins only"
                ON access.permissions
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.permissions',
            'Permissions are viewable by authenticated users',
            <<<'SQL'
                CREATE POLICY "Permissions are viewable by authenticated users"
                ON access.permissions
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING (true)
            SQL,
        );

        // access.departments policies (5)
        $this->createPolicyIfNotExists(
            'access.departments',
            'Departments are deletable by admins',
            <<<'SQL'
                CREATE POLICY "Departments are deletable by admins"
                ON access.departments
                AS PERMISSIVE
                FOR DELETE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.departments',
            'Departments are insertable by admins',
            <<<'SQL'
                CREATE POLICY "Departments are insertable by admins"
                ON access.departments
                AS PERMISSIVE
                FOR INSERT
                TO authenticated
                WITH CHECK ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.departments',
            'Departments are updatable by admins',
            <<<'SQL'
                CREATE POLICY "Departments are updatable by admins"
                ON access.departments
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.departments',
            'Departments are updatable by managers',
            <<<'SQL'
                CREATE POLICY "Departments are updatable by managers"
                ON access.departments
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'manager'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.departments',
            'Departments are viewable by authenticated users',
            <<<'SQL'
                CREATE POLICY "Departments are viewable by authenticated users"
                ON access.departments
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING ((is_active = true))
            SQL,
        );

        // access.user_roles policies (3)
        $this->createPolicyIfNotExists(
            'access.user_roles',
            'Admins can modify user roles',
            <<<'SQL'
                CREATE POLICY "Admins can modify user roles"
                ON access.user_roles
                AS PERMISSIVE
                FOR ALL
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_roles',
            'Admins can view all user roles',
            <<<'SQL'
                CREATE POLICY "Admins can view all user roles"
                ON access.user_roles
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_roles',
            'Users can view their own role',
            <<<'SQL'
                CREATE POLICY "Users can view their own role"
                ON access.user_roles
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING ((user_id = auth.uid()))
            SQL,
        );

        // access.user_departments policies (4)
        $this->createPolicyIfNotExists(
            'access.user_departments',
            'All authenticated users can view all department memberships',
            <<<'SQL'
                CREATE POLICY "All authenticated users can view all department memberships"
                ON access.user_departments
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING (true)
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_departments',
            'User department assignments are deletable by admins or managers',
            <<<'SQL'
                CREATE POLICY "User department assignments are deletable by admins or managers"
                ON access.user_departments
                AS PERMISSIVE
                FOR DELETE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND ((r.name = 'admin'::text) OR (r.name = 'manager'::text))))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_departments',
            'User department assignments are insertable by admins or manager',
            <<<'SQL'
                CREATE POLICY "User department assignments are insertable by admins or manager"
                ON access.user_departments
                AS PERMISSIVE
                FOR INSERT
                TO authenticated
                WITH CHECK ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND ((r.name = 'admin'::text) OR (r.name = 'manager'::text))))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_departments',
            'User department assignments are updatable by admins or managers',
            <<<'SQL'
                CREATE POLICY "User department assignments are updatable by admins or managers"
                ON access.user_departments
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND ((r.name = 'admin'::text) OR (r.name = 'manager'::text))))))
            SQL,
        );

        // access.user_permissions policies (5)
        $this->createPolicyIfNotExists(
            'access.user_permissions',
            'Admins and managers can view all direct permissions',
            <<<'SQL'
                CREATE POLICY "Admins and managers can view all direct permissions"
                ON access.user_permissions
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND ((r.name = 'admin'::text) OR (r.name = 'manager'::text))))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_permissions',
            'User permissions are deletable by admins',
            <<<'SQL'
                CREATE POLICY "User permissions are deletable by admins"
                ON access.user_permissions
                AS PERMISSIVE
                FOR DELETE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_permissions',
            'User permissions are insertable by admins',
            <<<'SQL'
                CREATE POLICY "User permissions are insertable by admins"
                ON access.user_permissions
                AS PERMISSIVE
                FOR INSERT
                TO authenticated
                WITH CHECK ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_permissions',
            'User permissions are updatable by admins',
            <<<'SQL'
                CREATE POLICY "User permissions are updatable by admins"
                ON access.user_permissions
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.user_permissions',
            'Users can view their own direct permissions',
            <<<'SQL'
                CREATE POLICY "Users can view their own direct permissions"
                ON access.user_permissions
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING ((user_id = auth.uid()))
            SQL,
        );

        // access.role_permissions policies (4)
        $this->createPolicyIfNotExists(
            'access.role_permissions',
            'Role permissions are creatable by admins only',
            <<<'SQL'
                CREATE POLICY "Role permissions are creatable by admins only"
                ON access.role_permissions
                AS PERMISSIVE
                FOR INSERT
                TO authenticated
                WITH CHECK ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.role_permissions',
            'Role permissions are deletable by admins only',
            <<<'SQL'
                CREATE POLICY "Role permissions are deletable by admins only"
                ON access.role_permissions
                AS PERMISSIVE
                FOR DELETE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.role_permissions',
            'Role permissions are modifiable by admins only',
            <<<'SQL'
                CREATE POLICY "Role permissions are modifiable by admins only"
                ON access.role_permissions
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
                WITH CHECK ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.role_permissions',
            'Role permissions are viewable by authenticated users',
            <<<'SQL'
                CREATE POLICY "Role permissions are viewable by authenticated users"
                ON access.role_permissions
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING (true)
            SQL,
        );

        // access.department_permissions policies (5)
        $this->createPolicyIfNotExists(
            'access.department_permissions',
            'Department permissions are deletable by admins',
            <<<'SQL'
                CREATE POLICY "Department permissions are deletable by admins"
                ON access.department_permissions
                AS PERMISSIVE
                FOR DELETE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.department_permissions',
            'Department permissions are insertable by admins',
            <<<'SQL'
                CREATE POLICY "Department permissions are insertable by admins"
                ON access.department_permissions
                AS PERMISSIVE
                FOR INSERT
                TO authenticated
                WITH CHECK ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.department_permissions',
            'Department permissions are updatable by admins',
            <<<'SQL'
                CREATE POLICY "Department permissions are updatable by admins"
                ON access.department_permissions
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'admin'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.department_permissions',
            'Department permissions are updatable by managers',
            <<<'SQL'
                CREATE POLICY "Department permissions are updatable by managers"
                ON access.department_permissions
                AS PERMISSIVE
                FOR UPDATE
                TO authenticated
                USING ((EXISTS ( SELECT 1
                   FROM (access.user_roles ur
                     JOIN access.roles r ON ((ur.role_id = r.id)))
                  WHERE ((ur.user_id = auth.uid()) AND (r.name = 'manager'::text)))))
            SQL,
        );

        $this->createPolicyIfNotExists(
            'access.department_permissions',
            'Department permissions are viewable by authenticated users',
            <<<'SQL'
                CREATE POLICY "Department permissions are viewable by authenticated users"
                ON access.department_permissions
                AS PERMISSIVE
                FOR SELECT
                TO authenticated
                USING (true)
            SQL,
        );
    }

    public function down(): void
    {
        $policies = [
            // access.roles
            ['access.roles', 'Roles are modifiable by admins only'],
            ['access.roles', 'Roles are viewable by authenticated users'],
            // access.permissions
            ['access.permissions', 'Only non-system permissions can be deleted by admins'],
            ['access.permissions', 'Permissions are modifiable by admins only'],
            ['access.permissions', 'Permissions are viewable by authenticated users'],
            // access.departments
            ['access.departments', 'Departments are deletable by admins'],
            ['access.departments', 'Departments are insertable by admins'],
            ['access.departments', 'Departments are updatable by admins'],
            ['access.departments', 'Departments are updatable by managers'],
            ['access.departments', 'Departments are viewable by authenticated users'],
            // access.user_roles
            ['access.user_roles', 'Admins can modify user roles'],
            ['access.user_roles', 'Admins can view all user roles'],
            ['access.user_roles', 'Users can view their own role'],
            // access.user_departments
            ['access.user_departments', 'All authenticated users can view all department memberships'],
            ['access.user_departments', 'User department assignments are deletable by admins or managers'],
            ['access.user_departments', 'User department assignments are insertable by admins or manager'],
            ['access.user_departments', 'User department assignments are updatable by admins or managers'],
            // access.user_permissions
            ['access.user_permissions', 'Admins and managers can view all direct permissions'],
            ['access.user_permissions', 'User permissions are deletable by admins'],
            ['access.user_permissions', 'User permissions are insertable by admins'],
            ['access.user_permissions', 'User permissions are updatable by admins'],
            ['access.user_permissions', 'Users can view their own direct permissions'],
            // access.role_permissions
            ['access.role_permissions', 'Role permissions are creatable by admins only'],
            ['access.role_permissions', 'Role permissions are deletable by admins only'],
            ['access.role_permissions', 'Role permissions are modifiable by admins only'],
            ['access.role_permissions', 'Role permissions are viewable by authenticated users'],
            // access.department_permissions
            ['access.department_permissions', 'Department permissions are deletable by admins'],
            ['access.department_permissions', 'Department permissions are insertable by admins'],
            ['access.department_permissions', 'Department permissions are updatable by admins'],
            ['access.department_permissions', 'Department permissions are updatable by managers'],
            ['access.department_permissions', 'Department permissions are viewable by authenticated users'],
        ];

        foreach ($policies as [$table, $policyName]) {
            $this->dropPolicyIfExists($table, $policyName);
        }
    }

    /**
     * Create a policy only if it doesn't already exist.
     *
     * This pattern is necessary because:
     * 1. Supabase may already have these policies in production
     * 2. CREATE POLICY fails if policy already exists (no IF NOT EXISTS support)
     * 3. Each policy is checked individually to handle partial migration runs
     */
    private function createPolicyIfNotExists(string $table, string $policyName, string $sql): void
    {
        [$schema, $tableName] = explode('.', $table);

        $exists = DB::selectOne(
            'SELECT 1 FROM pg_policies WHERE schemaname = ? AND tablename = ? AND policyname = ?',
            [$schema, $tableName, $policyName],
        );

        if ($exists === null) {
            DB::statement($sql);
        }
    }

    /**
     * Drop a policy only if it exists.
     */
    private function dropPolicyIfExists(string $table, string $policyName): void
    {
        DB::statement("DROP POLICY IF EXISTS \"{$policyName}\" ON {$table}");
    }
};
