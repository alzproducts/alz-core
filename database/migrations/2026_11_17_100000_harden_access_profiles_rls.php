<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hardens database authz on public.profiles, access.user_roles and public.user_profiles.
 *
 * Re-runnable: the auth.users email trigger is created only when pg_trigger has none, since the
 * postgres role holds TRIGGER on auth.users but does not own it, so cannot drop it; functions use
 * CREATE OR REPLACE; the view, access.get_user_profiles, the public.profiles trigger and policies
 * are dropped and re-created. Forward-only.
 *
 * @see https://github.com/alzproducts/alz-admin/issues/629 (ADM-59)
 */
return new class extends Migration {
    public function up(): void
    {
        $this->createUtilsFunctions();
        $this->addProfilesEmail();
        $this->recreateUserProfilesView();
        $this->recreatePolicies();
    }

    public function down(): void
    {
        throw new LogicException('forward-only');
    }

    private function createUtilsFunctions(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.current_user_is_approved()
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = ''
            AS $$
                SELECT coalesce((SELECT p.is_approved FROM public.profiles p WHERE p.id = auth.uid()), false)
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.mfa_satisfied()
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = ''
            AS $$
                SELECT coalesce(auth.jwt() ->> 'aal', '') = 'aal2'
                    OR NOT EXISTS (
                        SELECT 1 FROM auth.mfa_factors f
                        WHERE f.user_id = auth.uid() AND f.status = 'verified'
                    )
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.is_admin(check_user_id uuid)
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            BEGIN
                RETURN EXISTS (
                    SELECT 1
                    FROM access.user_roles ur
                    JOIN access.roles r ON ur.role_id = r.id
                    WHERE ur.user_id = check_user_id
                    AND r.name = 'admin'
                ) AND EXISTS (
                    SELECT 1 FROM public.profiles p WHERE p.id = check_user_id AND p.is_approved
                );
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.is_admin_or_manager(check_user_id uuid)
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            BEGIN
                RETURN EXISTS (
                    SELECT 1
                    FROM access.user_roles ur
                    JOIN access.roles r ON ur.role_id = r.id
                    WHERE ur.user_id = check_user_id
                    AND r.name IN ('admin', 'manager')
                ) AND EXISTS (
                    SELECT 1 FROM public.profiles p WHERE p.id = check_user_id AND p.is_approved
                );
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.check_user_role(user_id_param uuid, role_name_param text)
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            BEGIN
                RETURN EXISTS (
                    SELECT 1
                    FROM access.user_roles ur
                    JOIN access.roles r ON ur.role_id = r.id
                    WHERE ur.user_id = user_id_param
                    AND r.name = role_name_param
                ) AND EXISTS (
                    SELECT 1 FROM public.profiles p WHERE p.id = user_id_param AND p.is_approved
                );
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.enforce_signup_allowlist(event jsonb)
            RETURNS jsonb
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            DECLARE
                restricted constant jsonb := '{"error":{"http_code":403,"message":"Signups are restricted to approved addresses"}}';
                v_email text;
                v_domain text;
            BEGIN
                v_email := lower(trim(event -> 'user' ->> 'email'));

                IF v_email IS NULL OR v_email = '' THEN
                    RETURN '{"error":{"http_code":403,"message":"Email is required"}}'::jsonb;
                END IF;

                IF position('@' IN v_email) > 0 THEN
                    v_domain := substr(v_email, position('@' IN v_email) + 1);
                END IF;

                IF EXISTS (SELECT 1 FROM public.auth_allowed_domains d WHERE lower(trim(d.domain)) = v_domain)
                    OR EXISTS (SELECT 1 FROM public.auth_allowed_emails e WHERE lower(trim(e.email)) = v_email)
                THEN
                    RETURN '{}'::jsonb;
                END IF;

                RETURN restricted;
            EXCEPTION WHEN OTHERS THEN
                RETURN restricted;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.profile_email_from_auth()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            BEGIN
                NEW.email := (SELECT u.email FROM auth.users u WHERE u.id = NEW.id);
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.sync_profile_email()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $$
            BEGIN
                UPDATE public.profiles SET email = NEW.email WHERE id = NEW.id;
                RETURN NEW;
            END;
            $$
        SQL);

        $grants = [
            'utils.current_user_is_approved()' => ['authenticated'],
            'utils.mfa_satisfied()' => ['authenticated'],
            'utils.is_admin(uuid)' => ['authenticated'],
            'utils.is_admin_or_manager(uuid)' => ['authenticated'],
            'utils.check_user_role(uuid, text)' => ['authenticated'],
            'utils.enforce_signup_allowlist(jsonb)' => ['supabase_auth_admin'],
            'utils.profile_email_from_auth()' => [],
            'utils.sync_profile_email()' => [],
        ];

        foreach ($grants as $function => $grantees) {
            DB::statement("ALTER FUNCTION {$function} OWNER TO postgres");
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC, anon, authenticated, service_role");

            if ($grantees !== []) {
                DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO " . implode(', ', $grantees));
            }
        }

        DB::statement('GRANT USAGE ON SCHEMA utils TO supabase_auth_admin');
    }

    private function addProfilesEmail(): void
    {
        DB::statement('ALTER TABLE public.profiles ADD COLUMN IF NOT EXISTS email text');

        DB::statement('DROP TRIGGER IF EXISTS profiles_email_from_auth ON public.profiles');
        DB::statement(<<<'SQL'
            CREATE TRIGGER profiles_email_from_auth
                BEFORE INSERT OR UPDATE ON public.profiles
                FOR EACH ROW EXECUTE FUNCTION utils.profile_email_from_auth()
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_trigger t
                    WHERE t.tgrelid = 'auth.users'::regclass AND t.tgname = 'on_auth_user_email_updated'
                ) THEN
                    CREATE TRIGGER on_auth_user_email_updated
                        AFTER UPDATE OF email ON auth.users
                        FOR EACH ROW EXECUTE FUNCTION utils.sync_profile_email();
                END IF;
            END
            $$
        SQL);

        DB::statement('UPDATE public.profiles p SET email = u.email FROM auth.users u WHERE u.id = p.id');
    }

    private function recreateUserProfilesView(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS access.get_user_profiles(uuid)');
        DB::statement('DROP VIEW IF EXISTS public.user_profiles');

        DB::statement(<<<'SQL'
            CREATE VIEW public.user_profiles WITH (security_invoker = true) AS
            SELECT p.id, p.first_name, p.last_name, p.avatar_url,
                   p.created_at, p.is_approved, p.last_sign_in, p.updated_at,
                   p.email, r.name AS role_name,
                   COALESCE(json_agg(json_build_object('id', d.id, 'name', d.name))
                       FILTER (WHERE d.id IS NOT NULL), '[]'::json) AS departments
            FROM public.profiles p
            LEFT JOIN access.user_roles ur ON p.id = ur.user_id
            LEFT JOIN access.roles r ON ur.role_id = r.id
            LEFT JOIN access.user_departments ud ON p.id = ud.user_id
            LEFT JOIN access.departments d ON ud.department_id = d.id
            GROUP BY p.id, p.first_name, p.last_name, p.avatar_url,
                     p.created_at, p.is_approved, p.last_sign_in, p.updated_at,
                     p.email, r.name
        SQL);

        DB::statement('ALTER VIEW public.user_profiles OWNER TO postgres');
        DB::statement(<<<'SQL'
            COMMENT ON VIEW public.user_profiles IS 'Unified view of staff profiles with aggregated department data.
            Returns one row per user with basic profile information and departments as a JSON array.
            Rows are filtered by RLS on the underlying tables (security_invoker).'
        SQL);
        DB::statement('GRANT SELECT ON public.user_profiles TO authenticated, service_role');
        DB::statement('REVOKE ALL ON public.user_profiles FROM PUBLIC, anon');

        DB::statement(<<<'SQL'
            CREATE FUNCTION access.get_user_profiles(target_user_id uuid DEFAULT NULL)
            RETURNS SETOF public.user_profiles
            LANGUAGE plpgsql
            STABLE
            SECURITY INVOKER
            SET search_path = ''
            AS $$
            BEGIN
                IF auth.uid() IS NULL THEN
                    RAISE EXCEPTION 'Authentication required' USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NOT utils.current_user_is_approved() THEN
                    RAISE EXCEPTION 'Only approved staff members can access profile information'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NOT utils.mfa_satisfied() THEN
                    RAISE EXCEPTION 'Multi-factor authentication required' USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN QUERY
                    SELECT * FROM public.user_profiles up
                    WHERE target_user_id IS NULL OR up.id = target_user_id;
            END;
            $$
        SQL);

        DB::statement('ALTER FUNCTION access.get_user_profiles(uuid) OWNER TO postgres');
        DB::statement('REVOKE ALL ON FUNCTION access.get_user_profiles(uuid) FROM PUBLIC, anon');
        DB::statement('GRANT EXECUTE ON FUNCTION access.get_user_profiles(uuid) TO authenticated, service_role');
    }

    private function recreatePolicies(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                pol record;
            BEGIN
                FOR pol IN
                    SELECT schemaname, tablename, policyname FROM pg_policies
                    WHERE (schemaname, tablename) IN (('public', 'profiles'), ('access', 'user_roles'))
                LOOP
                    EXECUTE format('DROP POLICY %I ON %I.%I', pol.policyname, pol.schemaname, pol.tablename);
                END LOOP;
            END
            $$
        SQL);

        DB::statement('DROP POLICY IF EXISTS "Roles are modifiable by admins only" ON access.roles');
        DB::statement('DROP POLICY IF EXISTS "Roles are viewable by authenticated users" ON access.roles');

        $this->createProfilesPolicies();
        $this->createUserRolesPolicies();
    }

    private function createProfilesPolicies(): void
    {
        DB::statement(<<<'SQL'
            CREATE POLICY profiles_select_own_or_staff ON public.profiles
                AS PERMISSIVE FOR SELECT TO authenticated
                USING (id = auth.uid() OR (utils.current_user_is_approved() AND utils.mfa_satisfied()))
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY profiles_insert_own_unapproved ON public.profiles
                AS PERMISSIVE FOR INSERT TO authenticated
                WITH CHECK (id = auth.uid() AND is_approved = false)
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY profiles_update_own ON public.profiles
                AS PERMISSIVE FOR UPDATE TO authenticated
                USING (id = auth.uid())
                WITH CHECK (id = auth.uid() AND is_approved = utils.current_user_is_approved())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY profiles_update_approval_by_staff ON public.profiles
                AS PERMISSIVE FOR UPDATE TO authenticated
                USING (
                    (utils.check_user_role(auth.uid(), 'admin') OR utils.check_user_role(auth.uid(), 'manager'))
                    AND auth.uid() <> id
                    AND NOT (utils.check_user_role(auth.uid(), 'manager') AND utils.check_user_role(id, 'admin'))
                )
                WITH CHECK (
                    (utils.check_user_role(auth.uid(), 'admin') OR utils.check_user_role(auth.uid(), 'manager'))
                    AND auth.uid() <> id
                    AND NOT (utils.check_user_role(auth.uid(), 'manager') AND utils.check_user_role(id, 'admin'))
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY profiles_require_mfa_update ON public.profiles
                AS RESTRICTIVE FOR UPDATE TO authenticated
                USING (utils.mfa_satisfied())
                WITH CHECK (utils.mfa_satisfied())
        SQL);
    }

    private function createUserRolesPolicies(): void
    {
        $assignable = <<<'SQL'
            utils.is_admin(auth.uid())
            OR (
                utils.is_admin_or_manager(auth.uid())
                AND user_id <> auth.uid()
                AND (SELECT r.name FROM access.roles r WHERE r.id = role_id) IN ('standard', 'guest')
            )
        SQL;

        DB::statement(<<<'SQL'
            CREATE POLICY user_roles_select_own_or_staff ON access.user_roles
                AS PERMISSIVE FOR SELECT TO authenticated
                USING (user_id = auth.uid() OR utils.is_admin_or_manager(auth.uid()))
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY user_roles_insert_by_staff ON access.user_roles
                AS PERMISSIVE FOR INSERT TO authenticated
                WITH CHECK ({$assignable})
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY user_roles_update_by_staff ON access.user_roles
                AS PERMISSIVE FOR UPDATE TO authenticated
                USING ({$assignable})
                WITH CHECK ({$assignable})
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY user_roles_delete_by_staff ON access.user_roles
                AS PERMISSIVE FOR DELETE TO authenticated
                USING ({$assignable})
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY user_roles_require_mfa_insert ON access.user_roles
                AS RESTRICTIVE FOR INSERT TO authenticated
                WITH CHECK (utils.mfa_satisfied())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY user_roles_require_mfa_update ON access.user_roles
                AS RESTRICTIVE FOR UPDATE TO authenticated
                USING (utils.mfa_satisfied())
                WITH CHECK (utils.mfa_satisfied())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY user_roles_require_mfa_delete ON access.user_roles
                AS RESTRICTIVE FOR DELETE TO authenticated
                USING (utils.mfa_satisfied())
        SQL);
    }
};
