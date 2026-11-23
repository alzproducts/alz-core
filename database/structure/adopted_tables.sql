-- Structure of the 14 tables adopted from alz-admin, one fact per line, in a single result cell.
-- Read-only. Paste into the production SQL editor to compare with adopted_tables.txt, which describes production.
-- A change to an adopted table updates adopted_tables.txt in the same PR, captured from a migrated local database.
--
-- intentional: definition text renders by search_path, so "path" empties it for this statement only, and every
-- branch reads it through "t".
WITH path AS MATERIALIZED (
    SELECT set_config('search_path', '', true) AS value
), t AS MATERIALIZED (
    SELECT c.oid, n.nspname || '.' || c.relname AS tbl, c.relacl, c.relrowsecurity AS rls,
        c.relforcerowsecurity AS force_rls, pg_get_userbyid(c.relowner) AS owner
    FROM path
    JOIN pg_class c ON path.value = ''
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE c.relkind = 'r'
        AND (n.nspname = 'access'
            OR (n.nspname = 'config' AND c.relname = 'dashboard')
            OR (n.nspname = 'public'
                AND c.relname IN ('profiles', 'user_api_keys', 'auth_allowed_domains', 'auth_allowed_emails', 'system_cache')))
), facts AS (
    SELECT t.tbl, 'column' AS kind,
        a.attname || ' ' || format_type(a.atttypid, a.atttypmod)
            || CASE WHEN a.attnotnull THEN ' not null' ELSE ' null' END
            || ' default ' || coalesce(pg_get_expr(d.adbin, d.adrelid), '-') AS fact
    FROM t
    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum > 0 AND NOT a.attisdropped
    LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
    UNION ALL
    SELECT t.tbl, 'constraint', x.conname || ' ' || pg_get_constraintdef(x.oid)
    FROM t JOIN pg_constraint x ON x.conrelid = t.oid
    WHERE x.contype <> 'n'
    UNION ALL
    SELECT t.tbl, 'index', pg_get_indexdef(i.indexrelid)
    FROM t JOIN pg_index i ON i.indrelid = t.oid
    UNION ALL
    SELECT t.tbl, 'trigger', pg_get_triggerdef(g.oid)
    FROM t JOIN pg_trigger g ON g.tgrelid = t.oid
    WHERE NOT g.tgisinternal
    UNION ALL
    SELECT t.tbl, 'policy',
        p.polname || ' cmd ' || p.polcmd::text
            || CASE WHEN p.polpermissive THEN ' permissive' ELSE ' restrictive' END
            || ' to ' || (SELECT string_agg(CASE WHEN r = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(r)::text END, ',' ORDER BY pg_get_userbyid(r)::text COLLATE "C") FROM unnest(p.polroles) r)
            || ' using ' || coalesce(pg_get_expr(p.polqual, p.polrelid), '-')
            || ' check ' || coalesce(pg_get_expr(p.polwithcheck, p.polrelid), '-')
    FROM t JOIN pg_policy p ON p.polrelid = t.oid
    UNION ALL
    SELECT t.tbl, 'table', 'rls ' || t.rls::text || ' force_rls ' || t.force_rls::text || ' owner ' || t.owner FROM t
    UNION ALL
    SELECT t.tbl, 'grant',
        CASE WHEN x.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(x.grantee)::text END
            || ' ' || string_agg(x.privilege_type, ',' ORDER BY x.privilege_type COLLATE "C")
    FROM t, aclexplode(t.relacl) x
    GROUP BY t.tbl, x.grantee
), lines AS (
    SELECT tbl || ' ' || kind || ' ' || regexp_replace(fact, '\s+', ' ', 'g') AS line FROM facts
)
SELECT string_agg(line, E'\n' ORDER BY line COLLATE "C") AS structure FROM lines;
