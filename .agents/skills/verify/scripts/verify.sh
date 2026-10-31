#!/usr/bin/env bash
# alz-core verify helper: a private stack per run, driven over HTTP. See ../SKILL.md.
# Usage: bash <skill>/scripts/verify.sh <verb> [args]   (cwd = the repo worktree)
# Targets macOS /bin/bash 3.2: no associative arrays, no ${x,,}, no mapfile.
set -euo pipefail
umask 077

SKILL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
COMPOSE_FILE_PATH="$SKILL_DIR/scripts/compose.yaml"
PLACEHOLDER='verify-placeholder-not-a-credential'
FORBIDDEN_PORTS='54322 5432 6379 8000 8001'

# Every credential key an env() call under config/ reads: KEY:mode:config.path[,config.path]
# mode: blank (exported empty) | placeholder (non-working value) | run (run-random or run-derived)
CREDENTIAL_KEYS=(
    'SHOPWIRED_API_KEY:blank:shopwired.api_key'
    'SHOPWIRED_API_SECRET:blank:shopwired.api_secret'
    'SHOPWIRED_WEBHOOK_SECRET:blank:shopwired.webhook_secret'
    'LINNWORKS_APPLICATION_SECRET:blank:linnworks.application_secret'
    'LINNWORKS_INSTALLATION_TOKEN:blank:linnworks.installation_token'
    'HELPSCOUT_APP_SECRET:blank:helpscout.auth.appSecret'
    'HELPSCOUT_API_KEY:blank:helpscout.auth.apiKey'
    'GOOGLE_ADS_DEVELOPER_TOKEN:blank:google-ads.developer_token'
    'GOOGLE_ADS_CLIENT_SECRET:blank:google-ads.client_secret'
    'GOOGLE_ADS_REFRESH_TOKEN:blank:google-ads.refresh_token'
    'BING_ADS_CLIENT_SECRET:blank:bing-ads.client_secret'
    'BING_ADS_REFRESH_TOKEN:blank:bing-ads.refresh_token'
    'BING_ADS_DEVELOPER_TOKEN:blank:bing-ads.developer_token'
    'REVIEWSIO_API_KEY:blank:reviewsio.api_key'
    'MIXPANEL_SERVICE_ACCOUNT_USERNAME:blank:mixpanel.service_account_username'
    'MIXPANEL_SERVICE_ACCOUNT_PASSWORD:blank:mixpanel.service_account_password'
    'ANALYTICS_SALT:blank:mixpanel.analytics_salt'
    'SLACK_BOT_USER_OAUTH_TOKEN:blank:services.slack.notifications.bot_user_oauth_token'
    'LOG_SLACK_WEBHOOK_URL:blank:logging.channels.slack.url'
    'AWS_ACCESS_KEY_ID:blank:services.ses.key,filesystems.disks.s3.key'
    'AWS_SECRET_ACCESS_KEY:blank:services.ses.secret,filesystems.disks.s3.secret'
    'MAIL_URL:blank:mail.mailers.smtp.url'
    'MAIL_USERNAME:blank:mail.mailers.smtp.username'
    'MAIL_PASSWORD:blank:mail.mailers.smtp.password'
    'POSTMARK_TOKEN:blank:services.postmark.token'
    'RESEND_KEY:blank:services.resend.key'
    'TWILIO_AUTH_TOKEN:blank:call-tracking.twilio_auth_token'
    'SENTRY_LARAVEL_DSN:blank:sentry.dsn'
    'APP_KEY:run:app.key'
    'APP_PREVIOUS_KEYS:blank:app.previous_keys'
    'API_KEY_ENCRYPTION_SECRET:blank:app.api_key_encryption_secret'
    'SUPABASE_LOCAL_BYPASS_SECRET:run:services.supabase.local_bypass_secret'
    'HORIZON_USER:run:horizon.auth.username'
    'HORIZON_PASSWORD:run:horizon.auth.password'
    'DB_URL:blank:database.connections.pgsql.url,database.connections.pgsql_rls.url,database.connections.pgsql_admin.url'
    'DB_USERNAME:run:database.connections.pgsql.username,database.connections.pgsql_rls.username,database.connections.pgsql_admin.username'
    'DB_PASSWORD:run:database.connections.pgsql.password,database.connections.pgsql_rls.password,database.connections.pgsql_admin.password'
    'REDIS_URL:blank:database.redis.default.url,database.redis.cache.url,database.redis.queue.url'
    'REDIS_USERNAME:blank:database.redis.default.username,database.redis.cache.username,database.redis.queue.username'
    'REDIS_PASSWORD:run:database.redis.default.password,database.redis.cache.password,database.redis.queue.password'
)

# Keys the credential-name filter matches that are not credentials.
NOT_CREDENTIALS=(
    'AUTH_PASSWORD_BROKER'
    'AUTH_PASSWORD_RESET_TOKEN_TABLE'
    'AUTH_PASSWORD_TIMEOUT'
    'LOG_SLACK_USERNAME'
)

# Keys `test` passes to Pest besides the credentials; phpunit.xml's non-forced defaults fill the rest.
TEST_ENV_KEYS='DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_SSLMODE PGGSSENCMODE REDIS_HOST REDIS_PORT AWS_SHARED_CREDENTIALS_FILE AWS_CONFIG_FILE AWS_EC2_METADATA_DISABLED'

GUARD_PHP='$p = new \Illuminate\Support\ConfigurationUrlParser();
$hp = static function ($c) use ($p): string { $c = $p->parseConfiguration(is_array($c) ? $c : []); return ($c["host"] ?? "") . ":" . ($c["port"] ?? ""); };
$def = (string) config("database.default");
$db = ["default:" . $def => $hp(config("database.connections." . $def))];
foreach (["pgsql", "pgsql_rls", "pgsql_admin"] as $n) { $db[$n] = $hp(config("database.connections." . $n)); }
$redis = [];
foreach (["default", "cache", "queue", "horizon"] as $n) { if (config()->has("database.redis." . $n)) { $redis[$n] = $hp(config("database.redis." . $n)); } }
echo "VERIFY_JSON:" . json_encode(["db" => $db, "redis" => $redis, "octane_port" => config("octane.port"), "queue_default" => config("queue.default"), "cache_default" => config("cache.default")]) . PHP_EOL;'

CONFIG_PHP='$out = [];
foreach (explode(",", (string) getenv("VERIFY_CONFIG_PATHS")) as $k) {
    if (! config()->has($k)) { $out[$k] = ["kind" => "missing", "sha" => null]; continue; }
    $v = config($k);
    $kind = match (true) { $v === null => "null", $v === "" || $v === [] => "empty", is_string($v) || is_int($v) => "value", default => gettype($v) };
    $out[$k] = ["kind" => $kind, "sha" => is_scalar($v) ? hash("sha256", (string) $v) : null];
}
echo "VERIFY_JSON:" . json_encode($out) . PHP_EOL;'

die() {
    local code="$1"
    shift
    echo "verify.sh: $*" >&2
    exit "$code"
}

usage() {
    cat >&2 <<'USAGE'
Usage: bash <skill>/scripts/verify.sh <verb> [args]   (cwd = the repo worktree)
  launch  [--evidence <abs dir>] [--env-override K=V]...
  doctor  --evidence <dir>
  http    --evidence <dir> <METHOD> <path> [--data <json>]
  db      --evidence <dir> <sql>
  test    --evidence <dir> <pest path>
  note    --evidence <dir> <text>
  cleanup --evidence <dir>
Exit: 0 ok | 1 check failed | 64 usage | 75 environment not ready
USAGE
    exit 64
}

now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

sha256() { printf '%s' "$1" | shasum -a 256 | cut -d' ' -f1; }

note() { printf -- '- %s %s\n' "$(now_iso)" "$*" >>"$EVIDENCE/run-notes.md"; }

is_forbidden_port() {
    local p
    for p in $FORBIDDEN_PORTS; do
        [ "$1" = "$p" ] && return 0
    done
    return 1
}

is_credential_key() {
    local entry
    for entry in "${CREDENTIAL_KEYS[@]}"; do
        [ "${entry%%:*}" = "$1" ] && return 0
    done
    return 1
}

free_port() {
    local port
    while :; do
        port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);')"
        is_forbidden_port "$port" || { echo "$port"; return 0; }
    done
}

resolve_repo() {
    REPO="$(git rev-parse --show-toplevel 2>/dev/null)" || die 64 "run from inside the repo worktree (cwd is not a git work tree)"
}

# check_evidence <dir> <new|existing>
check_evidence() {
    local dir="$1"
    case "$dir" in
        /*) ;;
        *) die 64 "--evidence must be an absolute path: $dir" ;;
    esac
    [ -d "$dir" ] || die 64 "--evidence dir does not exist: $dir"
    if git -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        die 64 "--evidence dir is inside a git work tree: $dir"
    fi
    EVIDENCE="$(cd "$dir" && pwd -P)"
    if [ "$2" = new ]; then
        [ ! -e "$EVIDENCE/verify-state.json" ] || die 64 "--evidence dir already holds a run (verify-state.json): $EVIDENCE"
    else
        [ -f "$EVIDENCE/verify-state.json" ] || die 64 "--evidence dir holds no run (verify-state.json missing): $EVIDENCE"
    fi
}

state() { jq -r "$1" "$EVIDENCE/verify-state.json"; }

state_update() {
    local file="$EVIDENCE/verify-state.json"
    jq "$@" "$file" >"$file.tmp"
    mv "$file.tmp" "$file"
}

# record_resource <kind> <id> <pgid|''> <fingerprint|''>
record_resource() {
    state_update --arg k "$1" --arg id "$2" --arg pgid "$3" --arg fp "$4" --arg ts "$(now_iso)" \
        '.resources += [{kind: $k, id: $id, pgid: (if $pgid == "" then null else ($pgid | tonumber) end), fingerprint: (if $fp == "" then null else $fp end), startedAt: $ts}]'
}

load_state() {
    PROJECT="$(state .composeProject)"
    PORT_DB="$(state '.ports.db // empty')"
    PORT_REDIS="$(state '.ports.redis // empty')"
    PORT_HTTP="$(state '.ports.http // empty')"
}

dc() {
    docker compose -p "$PROJECT" --project-directory "$EVIDENCE/compose" -f "$COMPOSE_FILE_PATH" \
        --env-file "$EVIDENCE/compose/compose.env" "$@"
}

# ---- run env (plain array of K=V; bash 3.2) ----

ENV_LINES=()

set_env() {
    local i
    for i in ${ENV_LINES[@]+"${!ENV_LINES[@]}"}; do
        if [ "${ENV_LINES[$i]%%=*}" = "$1" ]; then
            ENV_LINES[i]="$1=$2"
            return 0
        fi
    done
    ENV_LINES+=("$1=$2")
}

load_run_env() {
    [ -f "$EVIDENCE/run.env" ] || die 1 "run.env missing: launch did not reach its env step"
    ENV_LINES=()
    local line
    while IFS= read -r line || [ -n "$line" ]; do
        [ -n "$line" ] && ENV_LINES+=("$line")
    done <"$EVIDENCE/run.env"
}

env_value() {
    local line
    for line in ${ENV_LINES[@]+"${ENV_LINES[@]}"}; do
        if [ "${line%%=*}" = "$1" ]; then
            printf '%s' "${line#*=}"
            return 0
        fi
    done
    return 1
}

# app_env <extra K=V>... -- <cmd>...: an app process under env -i with only PATH, HOME and the run env.
app_env() {
    local extra=()
    while [ "$#" -gt 0 ] && [ "$1" != -- ]; do
        extra+=("$1")
        shift
    done
    shift
    (cd "$REPO" && exec env -i "PATH=$PATH" "HOME=$HOME" ${ENV_LINES[@]+"${ENV_LINES[@]}"} ${extra[@]+"${extra[@]}"} "$@")
}

app_json() {
    local out
    out="$(app_env "$@" 2>&1)" || { echo "$out" >&2; return 1; }
    printf '%s\n' "$out" | sed -n 's/^VERIFY_JSON://p' | tail -1
}

build_run_env() {
    local db_password="$1" redis_password="$2" bypass="$3" supabase_port="$4"
    local sale_category entry key mode
    sale_category="$(sed -n 's/^SHOPWIRED_SALE_CATEGORY_ID=//p' "$REPO/.env.example" | head -1)"
    [ -n "$sale_category" ] || die 1 ".env.example sets no SHOPWIRED_SALE_CATEGORY_ID"

    ENV_LINES=()
    set_env APP_ENV local
    set_env APP_DEBUG true
    set_env APP_URL "http://127.0.0.1:$PORT_HTTP"
    set_env TELESCOPE_ENABLED false
    set_env LOG_CHANNEL single
    set_env OCTANE_SERVER swoole
    set_env OCTANE_PORT "$PORT_HTTP"
    set_env DB_CONNECTION pgsql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT "$PORT_DB"
    set_env DB_DATABASE verify
    set_env DB_SSLMODE disable
    set_env PGGSSENCMODE disable
    set_env REDIS_HOST 127.0.0.1
    set_env REDIS_PORT "$PORT_REDIS"
    set_env QUEUE_CONNECTION redis
    set_env CACHE_STORE redis
    set_env SESSION_DRIVER redis
    set_env SUPABASE_URL "http://127.0.0.1:$supabase_port"
    set_env SUPABASE_LOCAL_TEST_EMAIL dev@alzadmin.test
    set_env EMAIL_PRIMARY verify@example.invalid
    set_env SHOPWIRED_SALE_CATEGORY_ID "$sale_category"
    set_env AWS_SHARED_CREDENTIALS_FILE /dev/null
    set_env AWS_CONFIG_FILE /dev/null
    set_env AWS_EC2_METADATA_DISABLED true

    for entry in "${CREDENTIAL_KEYS[@]}"; do
        key="${entry%%:*}"
        mode="${entry#*:}"
        mode="${mode%%:*}"
        case "$mode" in
            blank) set_env "$key" '' ;;
            placeholder) set_env "$key" "$PLACEHOLDER" ;;
            run)
                case "$key" in
                    APP_KEY) ;; # set after composer install via key:generate --show
                    DB_USERNAME) set_env "$key" verify ;;
                    DB_PASSWORD) set_env "$key" "$db_password" ;;
                    REDIS_PASSWORD) set_env "$key" "$redis_password" ;;
                    SUPABASE_LOCAL_BYPASS_SECRET) set_env "$key" "$bypass" ;;
                    HORIZON_USER) set_env "$key" "verify-$(openssl rand -hex 4)" ;;
                    HORIZON_PASSWORD) set_env "$key" "$(openssl rand -hex 24)" ;;
                    *) die 1 "no run value defined for $key" ;;
                esac
                ;;
            *) die 1 "bad CREDENTIAL_KEYS mode for $key: $mode" ;;
        esac
    done
}

write_run_env() { printf '%s\n' "${ENV_LINES[@]}" >"$EVIDENCE/run.env"; }

# ---- checks ----

# guard <run|test>: resolved connections must be the run's own; prints one line per check.
guard() {
    local json fail=0 kind name value port expect
    json="$(app_json "$@")" || { echo "guard FAIL could not resolve config"; return 1; }
    [ -n "$json" ] || { echo "guard FAIL no config output"; return 1; }
    while IFS=' ' read -r kind name value; do
        port="${value##*:}"
        case "$kind" in
            db) expect="127.0.0.1:$PORT_DB" ;;
            redis) expect="127.0.0.1:$PORT_REDIS" ;;
        esac
        if [ -n "${expect#127.0.0.1:}" ] && [ "$value" = "$expect" ] && ! is_forbidden_port "$port"; then
            echo "guard ok $kind $name $value"
        else
            echo "guard FAIL $kind $name $value (want $expect)"
            fail=1
        fi
    done <<EOF
$(printf '%s\n' "$json" | jq -r '(.db | to_entries[] | "db \(.key) \(.value)"), (.redis | to_entries[] | "redis \(.key) \(.value)")')
EOF
    if [ "$(printf '%s\n' "$json" | jq '.redis | length')" -lt 3 ]; then
        echo "guard FAIL redis default/cache/queue connections not all resolved"
        fail=1
    fi
    GUARD_OCTANE_PORT="$(printf '%s\n' "$json" | jq -r '.octane_port')"
    if [ "${GUARD_MODE:-run}" = run ]; then
        if [ "$GUARD_OCTANE_PORT" = "$PORT_HTTP" ] && ! is_forbidden_port "$GUARD_OCTANE_PORT"; then
            echo "guard ok http octane.port $GUARD_OCTANE_PORT"
        else
            echo "guard FAIL http octane.port $GUARD_OCTANE_PORT (want $PORT_HTTP)"
            fail=1
        fi
    fi
    echo "guard info queue.default=$(printf '%s\n' "$json" | jq -r '.queue_default') cache.default=$(printf '%s\n' "$json" | jq -r '.cache_default')"
    return "$fail"
}

credential_check() {
    local paths='' entry key mode list path json kind sha want fail=0
    for entry in "${CREDENTIAL_KEYS[@]}"; do
        list="${entry#*:}"
        list="${list#*:}"
        paths="${paths:+$paths,}$list"
    done
    json="$(app_json "VERIFY_CONFIG_PATHS=$paths" -- php artisan tinker --execute="$CONFIG_PHP")" ||
        { echo "credential FAIL could not resolve config"; return 1; }
    [ -n "$json" ] || { echo "credential FAIL no config output"; return 1; }
    for entry in "${CREDENTIAL_KEYS[@]}"; do
        key="${entry%%:*}"
        mode="${entry#*:}"
        list="${mode#*:}"
        mode="${mode%%:*}"
        for path in $(printf '%s' "$list" | tr ',' ' '); do
            kind="$(printf '%s\n' "$json" | jq -r --arg k "$path" '.[$k].kind // "missing"')"
            sha="$(printf '%s\n' "$json" | jq -r --arg k "$path" '.[$k].sha // ""')"
            case "$mode" in
                blank) [ "$kind" = null ] || [ "$kind" = empty ] ;;
                placeholder) [ "$kind" = value ] && [ "$sha" = "$(sha256 "$PLACEHOLDER")" ] ;;
                run) want="$(env_value "$key")" && [ "$kind" = value ] && [ "$sha" = "$(sha256 "$want")" ] ;;
            esac && echo "credential ok $key $path ($mode)" ||
                { echo "credential FAIL $key $path (want $mode, got $kind)"; fail=1; }
        done
    done
    return "$fail"
}

run_guard() { GUARD_MODE=run guard -- php artisan tinker --execute="$GUARD_PHP"; }

# ---- processes ----

proc_command() { ps -ww -o command= -p "$1" 2>/dev/null || true; }

# spawn <name> -- <cmd>...: own process group (set -m; macOS has no setsid), recorded right after spawn.
# The fingerprint is the command line the exec'd process shows; cleanup signals the group only while it matches.
spawn() {
    local name="$1" pid i
    shift 2
    set -m
    (cd "$REPO" && exec env -i "PATH=$PATH" "HOME=$HOME" ${ENV_LINES[@]+"${ENV_LINES[@]}"} "$@") \
        </dev/null >"$EVIDENCE/logs/$name.log" 2>&1 &
    pid=$!
    set +m
    record_resource proc "$pid" "$pid" "$*"
    note "spawned $name pid=$pid"
    for i in $(seq 1 20); do
        [ "$(proc_command "$pid")" = "$*" ] && break
        sleep 0.25
    done
    [ "$(proc_command "$pid")" = "$*" ] || note "spawn $name: command line differs from fingerprint"
    SPAWNED_PID="$pid"
}

fail_launch() {
    note "launch failed: $*"
    die 1 "launch failed: $* (record kept; run cleanup --evidence $EVIDENCE)"
}

# ---- verbs ----

cmd_launch() {
    local evidence='' overrides=() kv key
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --evidence) [ "$#" -ge 2 ] || usage; evidence="$2"; shift 2 ;;
            --env-override)
                [ "$#" -ge 2 ] || usage
                kv="$2"
                key="${kv%%=*}"
                [ "$key" != "$kv" ] && [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || die 64 "--env-override wants K=V: $kv"
                case "$key" in
                    APP_ENV | PATH | HOME) die 64 "--env-override refuses $key" ;;
                esac
                ! is_credential_key "$key" || die 64 "--env-override refuses credential key $key"
                overrides+=("$kv")
                shift 2
                ;;
            *) usage ;;
        esac
    done

    resolve_repo
    if [ -z "$evidence" ]; then
        evidence="$("$HOME/.agents/bin/run-home" --workflow verify --new | jq -r '.run_dir')" ||
            die 75 "run-home could not mint an evidence dir"
    fi
    check_evidence "$evidence" new
    echo "evidence=$EVIDENCE"
    note "launch repo=$REPO"

    # 1. Preflight
    local tool f name pid
    for tool in docker jq curl php composer openssl lsof shasum; do
        command -v "$tool" >/dev/null 2>&1 || die 75 "missing tool: $tool"
    done
    docker info >/dev/null 2>&1 || die 75 "Docker is not reachable"
    for f in "$REPO"/.env*; do
        [ -e "$f" ] || continue
        name="$(basename "$f")"
        case "$name" in
            *.example) git -C "$REPO" ls-files --error-unmatch -- "$name" >/dev/null 2>&1 && continue ;;
        esac
        note "launch refused: $name present"
        die 1 "refusing: $name in the worktree would fill the run env (remove it or use a fresh worktree)"
    done
    if [ -e "$REPO/bootstrap/cache/config.php" ]; then
        note "launch refused: bootstrap/cache/config.php present"
        die 1 "refusing: bootstrap/cache/config.php skips env loading (run in a fresh worktree)"
    fi
    f="$REPO/storage/logs/octane-server-state.json"
    if [ -f "$f" ]; then
        for pid in $(jq -r '.. | .masterProcessId? // empty' "$f" 2>/dev/null); do
            if kill -0 "$pid" 2>/dev/null; then
                die 75 "Octane already runs from this worktree (pid $pid); use a fresh worktree"
            fi
        done
    fi

    # 2. Mint the run and bring up its private stack
    local run_id db_password redis_password bypass supabase_port
    run_id="$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 4)"
    PROJECT="alz-verify-$(printf '%s' "$run_id" | tr '[:upper:]' '[:lower:]')"
    db_password="$(openssl rand -hex 24)"
    redis_password="$(openssl rand -hex 24)"
    bypass="$(openssl rand -hex 24)"
    PORT_HTTP="$(free_port)"
    supabase_port="$(free_port)"
    while [ "$supabase_port" = "$PORT_HTTP" ]; do supabase_port="$(free_port)"; done

    mkdir -p "$EVIDENCE/compose" "$EVIDENCE/logs" "$EVIDENCE/http"
    printf '%s\n' 'VERIFY_DB_DATABASE=verify' 'VERIFY_DB_USERNAME=verify' \
        "VERIFY_DB_PASSWORD=$db_password" "VERIFY_REDIS_PASSWORD=$redis_password" >"$EVIDENCE/compose/compose.env"
    jq -n --arg runId "$run_id" --arg repo "$REPO" --arg project "$PROJECT" --argjson http "$PORT_HTTP" \
        '{runId: $runId, repoRoot: $repo, composeProject: $project, ports: {db: null, redis: null, http: $http}, resources: []}' \
        >"$EVIDENCE/verify-state.json"
    record_resource compose "$PROJECT" '' ''
    note "compose up $PROJECT"
    dc up -d --wait >"$EVIDENCE/logs/compose-up.log" 2>&1 || fail_launch "compose up (see logs/compose-up.log)"
    PORT_DB="$(dc port pgsql 5432 | sed 's/.*://')"
    PORT_REDIS="$(dc port redis 6379 | sed 's/.*://')"
    [[ "$PORT_DB" =~ ^[0-9]+$ ]] && [[ "$PORT_REDIS" =~ ^[0-9]+$ ]] || fail_launch "could not read published ports"
    state_update --argjson db "$PORT_DB" --argjson redis "$PORT_REDIS" '.ports.db = $db | .ports.redis = $redis'
    note "ports db=$PORT_DB redis=$PORT_REDIS http=$PORT_HTTP"

    # 3-4. Run env (APP_KEY needs vendor/), composer install, overrides
    build_run_env "$db_password" "$redis_password" "$bypass" "$supabase_port"
    for kv in ${overrides[@]+"${overrides[@]}"}; do
        set_env "${kv%%=*}" "${kv#*=}"
        note "env-override ${kv%%=*}"
    done
    write_run_env
    note "composer install"
    app_env -- composer install --no-interaction --no-progress >"$EVIDENCE/logs/composer.log" 2>&1 ||
        fail_launch "composer install (see logs/composer.log)"
    local app_key
    app_key="$(app_env -- php artisan key:generate --show --no-ansi 2>>"$EVIDENCE/logs/composer.log" | tr -d '[:space:]')"
    case "$app_key" in
        base64:*) set_env APP_KEY "$app_key" ;;
        *) fail_launch "key:generate --show returned no key" ;;
    esac
    write_run_env

    # 5. Guard and credential check, before anything touches a database
    note "guard"
    run_guard || fail_launch "isolation guard"
    credential_check || fail_launch "credential check"

    # 6. Migrate and seed the run's own Postgres
    note "migrate"
    app_env -- php artisan migrate --force --no-interaction >"$EVIDENCE/logs/migrate.log" 2>&1 ||
        fail_launch "migrate (see logs/migrate.log)"
    note "seed"
    app_env -- php artisan dev:seed-fake --no-interaction >"$EVIDENCE/logs/seed.log" 2>&1 ||
        fail_launch "seed (see logs/seed.log)"

    # 7. Octane and the queue worker
    local octane_pid worker_pid code='' i
    spawn octane -- php artisan octane:start --server=swoole --host=127.0.0.1 "--port=$GUARD_OCTANE_PORT" --workers=2
    octane_pid="$SPAWNED_PID"
    spawn worker -- php artisan queue:work redis "--name=verify-$run_id"
    worker_pid="$SPAWNED_PID"
    for i in $(seq 1 90); do
        code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT_HTTP/up" || true)"
        [ "$code" = 200 ] && break
        kill -0 "$octane_pid" 2>/dev/null || fail_launch "Octane exited (see logs/octane.log)"
        sleep 1
    done
    [ "$code" = 200 ] || fail_launch "Octane /up did not answer 200 (last $code)"
    sleep 5
    kill -0 "$worker_pid" 2>/dev/null || fail_launch "queue worker exited (see logs/worker.log)"

    # 8. Ready
    note "launched http://127.0.0.1:$PORT_HTTP"
    echo "base_url=http://127.0.0.1:$PORT_HTTP"
}

# common <args>: parse --evidence for a post-launch verb; leaves the rest in ARGS.
ARGS=()
common() {
    local evidence=''
    ARGS=()
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --evidence) [ "$#" -ge 2 ] || usage; evidence="$2"; shift 2 ;;
            *) ARGS+=("$1"); shift ;;
        esac
    done
    [ -n "$evidence" ] || usage
    resolve_repo
    check_evidence "$evidence" existing
    load_state
}

cmd_doctor() {
    common "$@"
    [ "${#ARGS[@]}" -eq 0 ] || usage
    load_run_env
    local fail=0
    run_guard || fail=1
    credential_check || fail=1
    if [ "$fail" -eq 0 ]; then note "doctor pass"; else note "doctor FAIL"; fi
    return "$fail"
}

cmd_http() {
    common "$@"
    local method='' path='' data='' have_data=0
    set -- ${ARGS[@]+"${ARGS[@]}"}
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --data) [ "$#" -ge 2 ] || usage; data="$2"; have_data=1; shift 2 ;;
            *)
                if [ -z "$method" ]; then method="$(printf '%s' "$1" | tr '[:lower:]' '[:upper:]')"
                elif [ -z "$path" ]; then path="$1"
                else usage; fi
                shift
                ;;
        esac
    done
    [ -n "$method" ] && [ -n "$path" ] || usage
    case "$path" in /*) ;; *) die 64 "path must start with /: $path" ;; esac
    load_run_env
    local secret stamp out hdr body status n=0 lower
    secret="$(env_value SUPABASE_LOCAL_BYPASS_SECRET)" || die 1 "run.env has no bypass secret"
    lower="$(printf '%s' "$method" | tr '[:upper:]' '[:lower:]')"
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    out="$EVIDENCE/http/$stamp-$lower.txt"
    while [ -e "$out" ]; do n=$((n + 1)); out="$EVIDENCE/http/$stamp-$n-$lower.txt"; done
    hdr="$out.hdr"
    body="$out.body"
    printf 'X-Local-Bypass: %s\n' "$secret" >"$out.req"
    local curl_args=(-sS -X "$method" -H 'Accept: application/json' -H "@$out.req" -D "$hdr" -o "$body" -w '%{http_code}')
    if [ "$have_data" -eq 1 ]; then curl_args+=(-H 'Content-Type: application/json' --data "$data"); fi
    status="$(curl ${curl_args[@]+"${curl_args[@]}"} "http://127.0.0.1:$PORT_HTTP$path")" || status="000"
    rm -f "$out.req"
    {
        echo "> $method http://127.0.0.1:$PORT_HTTP$path"
        echo '> Accept: application/json'
        echo '> X-Local-Bypass: [redacted]'
        if [ "$have_data" -eq 1 ]; then echo '> Content-Type: application/json'; echo '>'; echo "$data"; fi
        echo "< status $status"
        [ -f "$hdr" ] && sed 's/^/< /' "$hdr"
        echo
        [ -f "$body" ] && cat "$body"
    } >"$out"
    note "http $method $path -> $status (http/$(basename "$out"))"
    echo "status=$status"
    [ -f "$body" ] && cat "$body" && echo
    rm -f "$hdr" "$body"
    case "$status" in 2?? | 3??) return 0 ;; *) return 1 ;; esac
}

cmd_db() {
    common "$@"
    [ "${#ARGS[@]}" -eq 1 ] || usage
    note "db ${ARGS[0]}"
    dc exec -T -e 'PGOPTIONS=-c default_transaction_read_only=on' pgsql \
        psql -v ON_ERROR_STOP=1 -U verify -d verify -c "${ARGS[0]}" || return 1
}

cmd_test() {
    common "$@"
    [ "${#ARGS[@]}" -eq 1 ] || usage
    load_run_env
    local test_env=(APP_ENV=testing) entry key value rc=0
    for key in $TEST_ENV_KEYS; do
        value="$(env_value "$key")" || die 1 "run.env has no $key"
        test_env+=("$key=$value")
    done
    for entry in "${CREDENTIAL_KEYS[@]}"; do
        key="${entry%%:*}"
        [ "$key" = SUPABASE_LOCAL_BYPASS_SECRET ] && continue
        value="$(env_value "$key")" || die 1 "run.env has no $key"
        test_env+=("$key=$value")
    done
    ENV_LINES=("${test_env[@]}")
    GUARD_MODE=test guard -- php artisan tinker --execute="$GUARD_PHP" || { note "test ${ARGS[0]} refused by guard"; return 1; }
    note "test ${ARGS[0]}"
    app_env -- vendor/bin/pest "${ARGS[0]}" 2>&1 | tee "$EVIDENCE/logs/test-$(date -u +%Y%m%dT%H%M%SZ).log" || rc=1
    note "test ${ARGS[0]} rc=$rc"
    return "$rc"
}

cmd_note() {
    common "$@"
    [ "${#ARGS[@]}" -ge 1 ] || usage
    note "${ARGS[*]}"
}

cmd_cleanup() {
    common "$@"
    [ "${#ARGS[@]}" -eq 0 ] || usage
    [[ "$PROJECT" =~ ^alz-verify-[0-9a-z-]+$ ]] || die 1 "refusing cleanup: compose project '$PROJECT' is not a verify run"
    local n i kind pid pgid fp now fail=0 has_compose=0 port residue
    n="$(state '.resources | length')"
    i=$((n - 1))
    while [ "$i" -ge 0 ]; do
        kind="$(state ".resources[$i].kind")"
        if [ "$kind" = proc ]; then
            pid="$(state ".resources[$i].id")"
            pgid="$(state ".resources[$i].pgid")"
            fp="$(state ".resources[$i].fingerprint")"
            now="$(proc_command "$pid")"
            if [ -n "$now" ] && [ "$now" = "$fp" ] && [[ "$pgid" =~ ^[0-9]+$ ]]; then
                kill -TERM -- "-$pgid" 2>/dev/null || true
                local t
                for t in $(seq 1 30); do
                    kill -0 -- "-$pgid" 2>/dev/null || break
                    sleep 0.5
                done
                if kill -0 -- "-$pgid" 2>/dev/null; then kill -KILL -- "-$pgid" 2>/dev/null || true; fi
                note "cleanup stopped pgid=$pgid"
            else
                note "cleanup skipped pid=$pid (gone or fingerprint differs)"
            fi
        elif [ "$kind" = compose ]; then
            has_compose=1
        fi
        i=$((i - 1))
    done
    if [ "$has_compose" -eq 1 ] && [ -f "$EVIDENCE/compose/compose.env" ]; then
        dc down -v --remove-orphans >>"$EVIDENCE/logs/compose-down.log" 2>&1 || { echo "cleanup FAIL compose down"; fail=1; }
        note "cleanup compose down $PROJECT"
    fi

    residue="$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT")"
    if [ -n "$residue" ]; then echo "cleanup FAIL containers remain: $residue"; fail=1; fi
    residue="$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT")"
    if [ -n "$residue" ]; then echo "cleanup FAIL volumes remain: $residue"; fail=1; fi
    for port in $PORT_DB $PORT_REDIS $PORT_HTTP; do
        if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
            echo "cleanup FAIL listener remains on $port"
            fail=1
        fi
    done
    if [ "$fail" -eq 0 ]; then
        note "cleanup done, no residue"
        echo "cleanup ok"
    else
        note "cleanup FAIL residue"
    fi
    return "$fail"
}

[ "$#" -ge 1 ] || usage
verb="$1"
shift
case "$verb" in
    launch) cmd_launch "$@" ;;
    doctor) cmd_doctor "$@" ;;
    http) cmd_http "$@" ;;
    db) cmd_db "$@" ;;
    test) cmd_test "$@" ;;
    note) cmd_note "$@" ;;
    cleanup) cmd_cleanup "$@" ;;
    *) usage ;;
esac
