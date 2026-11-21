-- Installed as /docker-entrypoint-initdb.d/init-scripts/99-roles.sql (self-hosted Supabase pattern).
-- The image creates supabase_auth_admin with LOGIN and no password; Supabase Auth's migrate logs in as it.
\set pgpass `echo "$POSTGRES_PASSWORD"`

ALTER ROLE supabase_auth_admin WITH PASSWORD :'pgpass';
