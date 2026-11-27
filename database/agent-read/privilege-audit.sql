-- Privilege audit for the Agent database login: run it as that role (bin/agent-db -f <this file>).
-- Each row is a privilege the current role can use beyond its direct Read allowlist grants and the accepted
-- PUBLIC set (CONNECT and TEMPORARY on the database, USAGE on schema public, catalog reads). No rows = clean.
WITH me AS (
    SELECT oid, rolname, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls
    FROM pg_roles
    WHERE rolname = current_user
),
user_schema AS (
    SELECT n.oid, n.nspname,
           has_schema_privilege(n.oid, 'USAGE') AS usable,
           EXISTS (
               SELECT 1 FROM aclexplode(n.nspacl) a, me
               WHERE a.grantee = me.oid AND a.privilege_type = 'USAGE'
           ) AS direct
    FROM pg_namespace n
    WHERE n.nspname NOT LIKE 'pg\_%' AND n.nspname <> 'information_schema'
),
rel AS (
    SELECT c.oid, c.relkind, s.nspname || '.' || c.relname AS name, s.direct AS schema_direct
    FROM pg_class c
    JOIN user_schema s ON s.oid = c.relnamespace AND s.usable
    WHERE c.relkind IN ('r', 'v', 'm', 'p', 'f', 'S')
),
table_privilege AS (
    SELECT unnest(ARRAY['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN']) AS privilege
),
sequence_privilege AS (
    SELECT unnest(ARRAY['USAGE', 'SELECT', 'UPDATE']) AS privilege
),
column_privilege AS (
    SELECT unnest(ARRAY['SELECT', 'INSERT', 'UPDATE', 'REFERENCES']) AS privilege
)
SELECT kind, object, privilege
FROM (
    SELECT 'role attribute' AS kind, me.rolname::text AS object, attr.privilege
    FROM me
    CROSS JOIN LATERAL (VALUES
        ('SUPERUSER', me.rolsuper), ('CREATEROLE', me.rolcreaterole), ('CREATEDB', me.rolcreatedb),
        ('REPLICATION', me.rolreplication), ('BYPASSRLS', me.rolbypassrls)
    ) AS attr (privilege, held)
    WHERE attr.held

    UNION ALL
    SELECT 'role membership', m.roleid::regrole::text, 'MEMBER'
    FROM pg_auth_members m, me
    WHERE m.member = me.oid

    UNION ALL
    SELECT 'database', current_database()::text, 'CREATE'
    WHERE has_database_privilege(current_database(), 'CREATE')

    UNION ALL
    SELECT 'schema', s.nspname::text, 'USAGE'
    FROM user_schema s
    WHERE s.usable AND NOT s.direct AND s.nspname <> 'public'

    UNION ALL
    SELECT 'schema', s.nspname::text, 'CREATE'
    FROM user_schema s
    WHERE has_schema_privilege(s.oid, 'CREATE')

    UNION ALL
    SELECT 'table', r.name, p.privilege
    FROM rel r, table_privilege p
    WHERE r.relkind <> 'S' AND has_table_privilege(r.oid, p.privilege)

    UNION ALL
    SELECT 'sequence', r.name, p.privilege
    FROM rel r, sequence_privilege p
    WHERE r.relkind = 'S' AND has_sequence_privilege(r.oid, p.privilege)

    UNION ALL
    SELECT 'column', r.name || '.' || a.attname, p.privilege
    FROM rel r
    JOIN pg_attribute a ON a.attrelid = r.oid AND a.attnum > 0 AND NOT a.attisdropped
    CROSS JOIN column_privilege p
    WHERE r.relkind <> 'S'
      AND has_column_privilege(r.oid, a.attnum, p.privilege)
      AND NOT has_table_privilege(r.oid, p.privilege)
      AND NOT (
          p.privilege = 'SELECT' AND r.schema_direct AND EXISTS (
              SELECT 1 FROM aclexplode(a.attacl) x, me
              WHERE x.grantee = me.oid AND x.privilege_type = 'SELECT'
          )
      )

    UNION ALL
    SELECT 'security definer function', p.oid::regprocedure::text, 'EXECUTE'
    FROM pg_proc p
    JOIN user_schema s ON s.oid = p.pronamespace AND s.usable
    WHERE p.prosecdef AND has_function_privilege(p.oid, 'EXECUTE')

    UNION ALL
    SELECT 'default privilege', coalesce(n.nspname::text, '(all schemas)') || ' by ' || d.defaclrole::regrole::text, a.privilege_type
    FROM pg_default_acl d
    LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace
    CROSS JOIN LATERAL aclexplode(d.defaclacl) a
    JOIN me ON a.grantee = me.oid
) AS finding
ORDER BY kind, object, privilege;
