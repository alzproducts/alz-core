<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: access schema tables
 *
 * Creates all access.* tables for the RBAC system:
 *   - roles: guest, standard, manager, admin
 *   - permissions: action + resource combinations
 *   - departments: organizational units
 *   - user_roles: user-to-role assignment (1:1)
 *   - user_departments: user-to-department assignment (M:N)
 *   - user_permissions: direct user permissions with expiry
 *   - role_permissions: role-to-permission assignment (M:N)
 *   - department_permissions: department-to-permission assignment (M:N)
 *
 * RLS is ENABLED but policies are created in a separate migration (190012)
 * due to circular dependencies (policies reference user_roles which references roles).
 *
 * Note: FKs to auth.users are omitted (Supabase-managed, doesn't exist in tests).
 */
return new class extends Migration {
    public function up(): void
    {
        if ($this->tableExists('access', 'roles')) {
            return; // Skip if already exists (Supabase environment)
        }

        $this->createSequences();
        $this->createTables();
        $this->createIndexes();
        $this->createConstraints();
        $this->grantPermissions();
        $this->createTriggers();
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        DB::statement('DROP TABLE IF EXISTS access.department_permissions CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.role_permissions CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.user_permissions CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.user_departments CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.user_roles CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.departments CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.permissions CASCADE');
        DB::statement('DROP TABLE IF EXISTS access.roles CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS access.departments_id_seq CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS access.permissions_id_seq CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS access.roles_id_seq CASCADE');
    }

    private function createSequences(): void
    {
        DB::statement('CREATE SEQUENCE access.departments_id_seq');
        DB::statement('CREATE SEQUENCE access.permissions_id_seq');
        DB::statement('CREATE SEQUENCE access.roles_id_seq');
    }

    private function createTables(): void
    {
        // roles
        DB::statement(<<<'SQL'
            CREATE TABLE access.roles (
                id integer NOT NULL DEFAULT nextval('access.roles_id_seq'::regclass),
                name text NOT NULL,
                description text,
                is_active boolean DEFAULT true,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid,
                version integer DEFAULT 1
            )
        SQL);
        DB::statement('ALTER SEQUENCE access.roles_id_seq OWNED BY access.roles.id');
        DB::statement('ALTER TABLE access.roles ENABLE ROW LEVEL SECURITY');

        // permissions
        DB::statement(<<<'SQL'
            CREATE TABLE access.permissions (
                id integer NOT NULL DEFAULT nextval('access.permissions_id_seq'::regclass),
                action text NOT NULL,
                resource text NOT NULL,
                display_name text,
                description text,
                is_system boolean DEFAULT false,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid,
                version integer DEFAULT 1
            )
        SQL);
        DB::statement('ALTER SEQUENCE access.permissions_id_seq OWNED BY access.permissions.id');
        DB::statement('ALTER TABLE access.permissions ENABLE ROW LEVEL SECURITY');

        // departments
        DB::statement(<<<'SQL'
            CREATE TABLE access.departments (
                id integer NOT NULL DEFAULT nextval('access.departments_id_seq'::regclass),
                name text NOT NULL,
                description text,
                is_active boolean DEFAULT true,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid,
                version integer DEFAULT 1
            )
        SQL);
        DB::statement('ALTER SEQUENCE access.departments_id_seq OWNED BY access.departments.id');
        DB::statement('ALTER TABLE access.departments ENABLE ROW LEVEL SECURITY');

        // user_roles (1:1 - user has exactly one role)
        DB::statement(<<<'SQL'
            CREATE TABLE access.user_roles (
                user_id uuid NOT NULL,
                role_id integer NOT NULL,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid
            )
        SQL);
        DB::statement('ALTER TABLE access.user_roles ENABLE ROW LEVEL SECURITY');

        // user_departments (M:N)
        DB::statement(<<<'SQL'
            CREATE TABLE access.user_departments (
                user_id uuid NOT NULL,
                department_id integer NOT NULL,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid
            )
        SQL);
        DB::statement('ALTER TABLE access.user_departments ENABLE ROW LEVEL SECURITY');

        // user_permissions (direct permissions with expiry)
        DB::statement(<<<'SQL'
            CREATE TABLE access.user_permissions (
                user_id uuid NOT NULL,
                permission_id integer NOT NULL,
                expires_at timestamp with time zone,
                reason text,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid
            )
        SQL);
        DB::statement('ALTER TABLE access.user_permissions ENABLE ROW LEVEL SECURITY');

        // role_permissions (M:N)
        DB::statement(<<<'SQL'
            CREATE TABLE access.role_permissions (
                role_id integer NOT NULL,
                permission_id integer NOT NULL,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid
            )
        SQL);
        DB::statement('ALTER TABLE access.role_permissions ENABLE ROW LEVEL SECURITY');

        // department_permissions (M:N)
        DB::statement(<<<'SQL'
            CREATE TABLE access.department_permissions (
                department_id integer NOT NULL,
                permission_id integer NOT NULL,
                created_at timestamp with time zone,
                updated_at timestamp with time zone,
                added_by uuid,
                updated_by uuid
            )
        SQL);
        DB::statement('ALTER TABLE access.department_permissions ENABLE ROW LEVEL SECURITY');
    }

    private function createIndexes(): void
    {
        // roles
        DB::statement('CREATE UNIQUE INDEX roles_name_key ON access.roles USING btree (name)');
        DB::statement('CREATE UNIQUE INDEX roles_pkey ON access.roles USING btree (id)');

        // permissions
        DB::statement('CREATE INDEX idx_permissions_resource ON access.permissions USING btree (resource text_pattern_ops)');
        DB::statement('CREATE UNIQUE INDEX permissions_action_resource_key ON access.permissions USING btree (action, resource)');
        DB::statement('CREATE UNIQUE INDEX permissions_pkey ON access.permissions USING btree (id)');

        // departments
        DB::statement('CREATE UNIQUE INDEX departments_name_key ON access.departments USING btree (name)');
        DB::statement('CREATE UNIQUE INDEX departments_pkey ON access.departments USING btree (id)');

        // user_roles
        DB::statement('CREATE UNIQUE INDEX user_roles_pkey ON access.user_roles USING btree (user_id)');

        // user_departments
        DB::statement('CREATE INDEX idx_user_departments_department_id ON access.user_departments USING btree (department_id)');
        DB::statement('CREATE INDEX idx_user_departments_user_id ON access.user_departments USING btree (user_id)');
        DB::statement('CREATE UNIQUE INDEX user_departments_pkey ON access.user_departments USING btree (user_id, department_id)');

        // user_permissions
        DB::statement('CREATE INDEX idx_user_permissions_expires_at ON access.user_permissions USING btree (expires_at)');
        DB::statement('CREATE INDEX idx_user_permissions_permission_id ON access.user_permissions USING btree (permission_id)');
        DB::statement('CREATE INDEX idx_user_permissions_user_id ON access.user_permissions USING btree (user_id)');
        DB::statement('CREATE UNIQUE INDEX user_permissions_pkey ON access.user_permissions USING btree (user_id, permission_id)');

        // role_permissions
        DB::statement('CREATE INDEX idx_role_permissions_permission_id ON access.role_permissions USING btree (permission_id)');
        DB::statement('CREATE INDEX idx_role_permissions_role_id ON access.role_permissions USING btree (role_id)');
        DB::statement('CREATE UNIQUE INDEX role_permissions_pkey ON access.role_permissions USING btree (role_id, permission_id)');

        // department_permissions
        DB::statement('CREATE INDEX idx_department_permissions_department_id ON access.department_permissions USING btree (department_id)');
        DB::statement('CREATE INDEX idx_department_permissions_permission_id ON access.department_permissions USING btree (permission_id)');
        DB::statement('CREATE UNIQUE INDEX department_permissions_pkey ON access.department_permissions USING btree (department_id, permission_id)');
    }

    private function createConstraints(): void
    {
        // Primary keys (using indexes)
        DB::statement('ALTER TABLE access.roles ADD CONSTRAINT roles_pkey PRIMARY KEY USING INDEX roles_pkey');
        DB::statement('ALTER TABLE access.permissions ADD CONSTRAINT permissions_pkey PRIMARY KEY USING INDEX permissions_pkey');
        DB::statement('ALTER TABLE access.departments ADD CONSTRAINT departments_pkey PRIMARY KEY USING INDEX departments_pkey');
        DB::statement('ALTER TABLE access.user_roles ADD CONSTRAINT user_roles_pkey PRIMARY KEY USING INDEX user_roles_pkey');
        DB::statement('ALTER TABLE access.user_departments ADD CONSTRAINT user_departments_pkey PRIMARY KEY USING INDEX user_departments_pkey');
        DB::statement('ALTER TABLE access.user_permissions ADD CONSTRAINT user_permissions_pkey PRIMARY KEY USING INDEX user_permissions_pkey');
        DB::statement('ALTER TABLE access.role_permissions ADD CONSTRAINT role_permissions_pkey PRIMARY KEY USING INDEX role_permissions_pkey');
        DB::statement('ALTER TABLE access.department_permissions ADD CONSTRAINT department_permissions_pkey PRIMARY KEY USING INDEX department_permissions_pkey');

        // Unique constraints
        DB::statement('ALTER TABLE access.roles ADD CONSTRAINT roles_name_key UNIQUE USING INDEX roles_name_key');
        DB::statement('ALTER TABLE access.departments ADD CONSTRAINT departments_name_key UNIQUE USING INDEX departments_name_key');
        DB::statement('ALTER TABLE access.permissions ADD CONSTRAINT permissions_action_resource_key UNIQUE USING INDEX permissions_action_resource_key');

        // CHECK constraints
        DB::statement(<<<'SQL'
            ALTER TABLE access.roles ADD CONSTRAINT roles_name_check
            CHECK ((name = ANY (ARRAY['guest'::text, 'standard'::text, 'manager'::text, 'admin'::text]))) NOT VALID
        SQL);
        DB::statement('ALTER TABLE access.roles VALIDATE CONSTRAINT roles_name_check');

        DB::statement(<<<'SQL'
            ALTER TABLE access.permissions ADD CONSTRAINT permissions_action_check
            CHECK ((action = ANY (ARRAY['view'::text, 'create'::text, 'edit'::text, 'delete'::text, 'export'::text, 'manage'::text, '*'::text]))) NOT VALID
        SQL);
        DB::statement('ALTER TABLE access.permissions VALIDATE CONSTRAINT permissions_action_check');

        // Foreign keys between access tables (skip auth.users FKs)
        // user_roles -> roles
        DB::statement('ALTER TABLE access.user_roles ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES access.roles(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.user_roles VALIDATE CONSTRAINT user_roles_role_id_fkey');

        // user_departments -> departments
        DB::statement('ALTER TABLE access.user_departments ADD CONSTRAINT user_departments_department_id_fkey FOREIGN KEY (department_id) REFERENCES access.departments(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.user_departments VALIDATE CONSTRAINT user_departments_department_id_fkey');

        // user_permissions -> permissions
        DB::statement('ALTER TABLE access.user_permissions ADD CONSTRAINT user_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES access.permissions(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.user_permissions VALIDATE CONSTRAINT user_permissions_permission_id_fkey');

        // role_permissions -> roles, permissions
        DB::statement('ALTER TABLE access.role_permissions ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES access.roles(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.role_permissions VALIDATE CONSTRAINT role_permissions_role_id_fkey');
        DB::statement('ALTER TABLE access.role_permissions ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES access.permissions(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.role_permissions VALIDATE CONSTRAINT role_permissions_permission_id_fkey');

        // department_permissions -> departments, permissions
        DB::statement('ALTER TABLE access.department_permissions ADD CONSTRAINT department_permissions_department_id_fkey FOREIGN KEY (department_id) REFERENCES access.departments(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.department_permissions VALIDATE CONSTRAINT department_permissions_department_id_fkey');
        DB::statement('ALTER TABLE access.department_permissions ADD CONSTRAINT department_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES access.permissions(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE access.department_permissions VALIDATE CONSTRAINT department_permissions_permission_id_fkey');
    }

    private function grantPermissions(): void
    {
        $tables = [
            'roles', 'permissions', 'departments',
            'user_roles', 'user_departments', 'user_permissions',
            'role_permissions', 'department_permissions',
        ];
        $roles = ['anon', 'authenticated', 'service_role'];
        $permissions = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

        foreach ($tables as $table) {
            foreach ($roles as $role) {
                foreach ($permissions as $permission) {
                    DB::statement("GRANT {$permission} ON TABLE access.{$table} TO {$role}");
                }
            }
        }

        // Grant sequence usage
        $sequences = ['roles_id_seq', 'permissions_id_seq', 'departments_id_seq'];
        foreach ($sequences as $seq) {
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE access.{$seq} TO authenticated, service_role");
        }
    }

    private function createTriggers(): void
    {
        // Roles triggers
        DB::statement('CREATE TRIGGER increment_roles_version BEFORE UPDATE ON access.roles FOR EACH ROW EXECUTE FUNCTION increment_version()');
        DB::statement('CREATE TRIGGER set_roles_audit_insert BEFORE INSERT ON access.roles FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_roles_audit_update BEFORE UPDATE ON access.roles FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // Permissions triggers
        DB::statement('CREATE TRIGGER increment_permissions_version BEFORE UPDATE ON access.permissions FOR EACH ROW EXECUTE FUNCTION increment_version()');
        DB::statement('CREATE TRIGGER set_permissions_audit_insert BEFORE INSERT ON access.permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_permissions_audit_update BEFORE UPDATE ON access.permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // Departments triggers
        DB::statement('CREATE TRIGGER increment_departments_version BEFORE UPDATE ON access.departments FOR EACH ROW EXECUTE FUNCTION increment_version()');
        DB::statement('CREATE TRIGGER set_departments_audit_insert BEFORE INSERT ON access.departments FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_departments_audit_update BEFORE UPDATE ON access.departments FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // user_roles triggers
        DB::statement('CREATE TRIGGER set_user_roles_audit_insert BEFORE INSERT ON access.user_roles FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_user_roles_audit_update BEFORE UPDATE ON access.user_roles FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // user_departments triggers
        DB::statement('CREATE TRIGGER set_user_departments_audit_insert BEFORE INSERT ON access.user_departments FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_user_departments_audit_update BEFORE UPDATE ON access.user_departments FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // user_permissions triggers
        DB::statement('CREATE TRIGGER set_user_permissions_audit_insert BEFORE INSERT ON access.user_permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_user_permissions_audit_update BEFORE UPDATE ON access.user_permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // role_permissions triggers
        DB::statement('CREATE TRIGGER set_role_permissions_audit_insert BEFORE INSERT ON access.role_permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_role_permissions_audit_update BEFORE UPDATE ON access.role_permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');

        // department_permissions triggers
        DB::statement('CREATE TRIGGER set_department_permissions_audit_insert BEFORE INSERT ON access.department_permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_insert()');
        DB::statement('CREATE TRIGGER set_department_permissions_audit_update BEFORE UPDATE ON access.department_permissions FOR EACH ROW EXECUTE FUNCTION set_audit_fields_update()');
    }

    private function tableExists(string $schema, string $table): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = ? AND table_name = ?
            ) as exists
        SQL, [$schema, $table]);

        return $result->exists;
    }
};
