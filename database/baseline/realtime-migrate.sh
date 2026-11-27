#!/bin/bash
# Realtime's tenant migrations on the Supabase baseline (ADR-0016), run as `bash <this file>` in the supabase/realtime image.
# Inputs: DB_HOST and DB_PASSWORD (supabase_admin's) required; DB_PORT (5432) and SELF_HOST_TENANT_DB_PORT (DB_PORT) optional.
set -euo pipefail

: "${DB_HOST:?}" "${DB_PASSWORD:?}"
export DB_PORT="${DB_PORT:-5432}"
export SELF_HOST_TENANT_DB_PORT="${SELF_HOST_TENANT_DB_PORT:-$DB_PORT}"
export DB_USER=supabase_admin DB_NAME=postgres
export DB_AFTER_CONNECT_QUERY='SET search_path TO _realtime'
export APP_NAME=realtime SELF_HOST_TENANT_NAME=realtime-baseline
export ERL_AFLAGS='-proto_dist inet_tcp' LANG=C.UTF-8
# intentional: placeholders; the one-shot never serves a request, and Realtime refuses to boot without them.
export DB_ENC_KEY=baselinerealtime
export API_JWT_SECRET=baseline-migrate-placeholder-secret-not-used
export SECRET_KEY_BASE=baseline-migrate-placeholder-secret-key-base-not-used-0123456789abcdef0123456789
export METRICS_JWT_SECRET=baseline-migrate-placeholder-metrics-not-used

cd /app

connect='Application.ensure_all_started(:postgrex)
{:ok, c} = Postgrex.start_link(hostname: System.fetch_env!("DB_HOST"), port: String.to_integer(System.fetch_env!("DB_PORT")), username: "supabase_admin", password: System.fetch_env!("DB_PASSWORD"), database: "postgres")'

/app/bin/realtime eval "$connect
Postgrex.query!(c, \"create schema if not exists _realtime authorization supabase_admin\", [])"

/app/bin/migrate

# intentional: seeds.exs only logs a failed tenant set-up and exits 0 (COR-381 P1).
seed_out="$(/app/bin/realtime eval 'Realtime.Release.seeds(Realtime.Repo)' 2>&1)"
printf '%s\n' "$seed_out"
if [[ "$seed_out" == *'Failed to set-up tenant'* ]]; then
    echo 'realtime-migrate: tenant set-up failed' >&2
    exit 1
fi

# shellcheck disable=SC2016 # intentional: $1-$3 are SQL parameters, not shell expansions.
/app/bin/realtime eval "$connect"'
migrations = case Postgrex.query(c, "select count(*) from realtime.schema_migrations", []) do
  {:ok, %{rows: [[n]]}} -> n
  _ -> 0
end
if migrations == 0 do
  IO.puts(:stderr, "realtime-migrate: realtime.schema_migrations is missing or empty")
  System.halt(1)
end
probe = Postgrex.transaction(c, fn conn ->
  Postgrex.rollback(conn, Postgrex.query(conn, "insert into realtime.messages (topic, extension, inserted_at) values ($1, $2, now() at time zone $3)", ["realtime-migrate-probe", "broadcast", "utc"]))
end)
case probe do
  {:error, {:ok, _}} -> IO.puts("realtime-migrate: #{migrations} tenant migrations; a realtime.messages partition covers today (UTC)")
  other ->
    IO.puts(:stderr, "realtime-migrate: no realtime.messages partition covers today (UTC): #{inspect(other)}")
    System.halt(1)
end'
