#!/usr/bin/env bash
# Bench journeys for products-perf.md, run against an `up` stack through the verify wrapper's exec verb.
# Usage (cwd = the repo checkout):
#   .agents/skills/verify/features/products-perf.sh load    --evidence <dir> [--requests N] [--warmup N] [--rate R]
#   .agents/skills/verify/features/products-perf.sh writers --evidence <dir> [--runs N] [--baseline <evidence dir>]
# Load requests and the Octane request log stay inside the app container; writers run in the worker container.
set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
VERIFY="$HERE/../verify"
BENCH_SRC="$HERE/products-perf.php"
BENCH=/tmp/products-perf.php
LOGS=/var/www/html/storage/logs

die() {
    local code="$1"
    shift
    echo "products-perf: $*" >&2
    exit "$code"
}

usage() {
    sed -n '3,5p' "${BASH_SOURCE[0]}" | sed 's/^# //' >&2
    exit 64
}

int_opt() { [[ "$2" =~ ^[1-9][0-9]*$ ]] || die 64 "$1 wants a positive whole number: $2"; }

v() { "$VERIFY" "$1" --evidence "$EVIDENCE" "${@:2}"; }

# install <service>: copies the helper into the service container over stdin.
install() { v exec "$1" -- sh -c "cat > $BENCH" <"$BENCH_SRC" >/dev/null; }

# claim <journey>: writers mutate the seed and restore nothing, so each run serves one bench journey.
claim() {
    local marker="$EVIDENCE/bench/claimed"
    [ ! -f "$marker" ] || die 64 "this run already served $(cat "$marker"); run down and up again for $1"
    mkdir -p "$EVIDENCE/bench"
    echo "$1" >"$marker"
}

# shared_dir <service> <name>: a dir under <evidence>/logs/<service>/ that www-data can write; prints both paths.
shared_dir() {
    mkdir -p "$EVIDENCE/logs/$1/$2"
    # intentional: www-data (uid 33) writes here through the storage/logs bind mount.
    chmod 0777 "$EVIDENCE/logs/$1/bench" "$EVIDENCE/logs/$1/$2"
    HOST_DIR="$EVIDENCE/logs/$1/$2"
    CONTAINER_DIR="$LOGS/$2"
}

cmd_load() {
    local requests=2000 warmup=100 rate=2
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --requests) [ "$#" -ge 2 ] || usage; int_opt "$1" "$2"; requests="$2"; shift 2 ;;
            --warmup) [ "$#" -ge 2 ] || usage; int_opt "$1" "$2"; warmup="$2"; shift 2 ;;
            --rate) [ "$#" -ge 2 ] || usage; int_opt "$1" "$2"; rate="$2"; shift 2 ;;
            *) usage ;;
        esac
    done
    claim load
    install app
    install worker
    local stamp app_host app_dir worker_host worker_dir
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    shared_dir app "bench/load-$stamp"
    app_host="$HOST_DIR"
    app_dir="$CONTAINER_DIR"
    shared_dir worker "bench/load-$stamp"
    worker_host="$HOST_DIR"
    worker_dir="$CONTAINER_DIR"
    v note "bench load requests=$requests warmup=$warmup rate=$rate dir=logs/app/bench/load-$stamp"
    v exec app -- php "$BENCH" shapes --out "$app_dir/shapes.json" >/dev/null || die 1 "could not derive the request mix from the seed"

    # Octane's request lines go to the app container's stdout; follow them into a file the load reads via the mount.
    local octane_log="$app_host/octane-stdout.log" follow_pid
    : >"$octane_log"
    chmod 0644 "$octane_log"
    docker logs -f "$(project_app_container)" >"$octane_log" 2>&1 &
    follow_pid=$!

    local deadline schedule kind interval delay pids=() kinds=() fail=0
    deadline=$(($(date +%s) + (warmup + requests) / rate + 600))
    schedule="$(v exec worker -- php "$BENCH" writers)" || die 1 "could not list the background writers"
    while IFS=' ' read -r kind interval delay; do
        [[ "$kind" =~ ^[a-z_]+$ ]] || continue
        v exec worker -- php "$BENCH" writer --kind "$kind" --stop-file "$worker_dir/stop" --deadline "$deadline" \
            --once-offset "$((warmup / rate))" --result "$worker_dir/$kind.json" >"$worker_host/$kind.log" 2>&1 &
        pids+=("$!")
        kinds+=("$kind")
        v note "bench writer $kind every=$interval first=${delay}s"
    done <<EOF
$schedule
EOF

    echo "bench load: ~$(((warmup + requests) / rate))s at $rate req/s; writers running"
    # The bypass secret stays in the container: the helper reads it from the app's own env.
    # shellcheck disable=SC2016
    v exec app -- sh -c 'VERIFY_BENCH_BYPASS="$SUPABASE_LOCAL_BYPASS_SECRET" exec php "$@"' sh "$BENCH" load \
        --port 8000 --shapes "$app_dir/shapes.json" --log "$app_dir/octane-stdout.log" \
        --warmup "$warmup" --requests "$requests" --rate "$rate" --result "$app_dir/load.json" || fail=1

    touch "$worker_host/stop"
    local pid
    for pid in ${pids[@]+"${pids[@]}"}; do wait "$pid" || true; done
    kill "$follow_pid" 2>/dev/null || true

    [ -f "$app_host/load.json" ] || { v note "bench load FAIL no result"; die 1 "load produced no result (see output above)"; }
    local summary="$EVIDENCE/bench/load-$stamp.json" writers='{}' result i
    for i in "${!kinds[@]}"; do
        kind="${kinds[$i]}"
        result="$worker_host/$kind.json"
        if [ -f "$result" ]; then
            writers="$(jq -c --slurpfile r "$result" --arg k "$kind" \
                '. + {($k): {writes: $r[0].writes, error: $r[0].error, max_write_ms: ($r[0].write_ms | max)}}' <<<"$writers")"
        else
            writers="$(jq -c --arg k "$kind" '. + {($k): {writes: 0, error: "no result file (see logs/worker/bench/load-'"$stamp"'/\($k).log)", max_write_ms: null}}' <<<"$writers")"
        fi
    done
    jq --argjson writers "$writers" '. + {writers: $writers}' "$app_host/load.json" >"$summary"
    jq -r '"p50_ms=\(.p50_ms) p95_ms=\(.p95_ms) p99_ms=\(.p99_ms) max_ms=\(.max_ms)",
        "requests_sent=\(.requests_sent) server_lines=\(.server_lines) server_statuses=\(.server_statuses | tojson) client_statuses=\(.client_statuses | tojson)",
        (.writers | to_entries[] | "writer \(.key) writes=\(.value.writes) max_write_ms=\(.value.max_write_ms) error=\(.value.error // "none")")' "$summary"
    echo "result=$summary"

    if [ "$(jq '[.writers[] | select(.error != null)] | length' "$summary")" != 0 ]; then
        echo "bench load FAIL a background writer errored"
        fail=1
    fi
    local silent
    silent="$(jq -r '[.writers | to_entries[] | select(.value.writes == 0) | .key] | join(" ")' "$summary")"
    if [ -n "$silent" ]; then
        echo "bench load FAIL writers that did not fire: $silent"
        fail=1
    fi
    if [ "$(jq '.client_transport_failures // 0' "$summary")" != 0 ]; then
        echo "bench load FAIL saturated: $(jq '.client_transport_failures' "$summary") requests timed out client-side; the server could not keep up with $rate req/s, so the percentiles are not a valid reading"
        fail=1
    fi
    if [ "$(jq '.server_lines' "$summary")" != "$requests" ] || [ "$(jq -r '.server_statuses | keys | join(",")' "$summary")" != 200 ]; then
        echo "bench load FAIL server lines or statuses differ from $requests x 200"
        fail=1
    fi
    v note "bench load p99_ms=$(jq -r '.p99_ms' "$summary") rc=$fail"
    return "$fail"
}

cmd_writers() {
    local runs=5 baseline='' base_json=''
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --runs) [ "$#" -ge 2 ] || usage; int_opt "$1" "$2"; runs="$2"; shift 2 ;;
            --baseline) [ "$#" -ge 2 ] || usage; baseline="$2"; shift 2 ;;
            *) usage ;;
        esac
    done
    if [ -n "$baseline" ]; then
        base_json="$(find "$baseline/bench" -maxdepth 1 -name 'writers-*.json' 2>/dev/null | sort | tail -1)"
        [ -n "$base_json" ] || die 64 "--baseline holds no bench/writers-*.json: $baseline"
    fi
    claim writers
    install worker
    local stamp out
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    shared_dir worker "bench/writers-$stamp"
    out="$EVIDENCE/bench/writers-$stamp.json"
    v note "bench writers runs=$runs"
    v exec worker -- php "$BENCH" time-writers --runs "$runs" --result "$CONTAINER_DIR/result.json" >/dev/null ||
        { v note "bench writers FAIL"; return 1; }
    cp "$HOST_DIR/result.json" "$out"
    jq -r '.writers | to_entries[] | "\(.key) median_ms=\(.value.median_ms) runs_ms=\(.value.runs_ms | join(","))"' "$out"
    if [ -n "$base_json" ]; then
        echo "baseline=$base_json"
        jq -rn --slurpfile h "$out" --slurpfile b "$base_json" \
            '$h[0].writers | keys[] as $k | "\($k) ratio=\(($h[0].writers[$k].median_ms / $b[0].writers[$k].median_ms * 1000 | round) / 1000) head_median_ms=\($h[0].writers[$k].median_ms) base_median_ms=\($b[0].writers[$k].median_ms)"'
    fi
    echo "result=$out"
    v note "bench writers done result=bench/$(basename "$out")"
}

project_app_container() {
    local project
    project="$(sed -n 's/^project=//p' "$EVIDENCE/.verify/state.env" | tail -1)"
    [ -n "$project" ] || die 75 "no run in this evidence dir (run up first): $EVIDENCE"
    docker ps -q --filter "label=com.docker.compose.project=$project" --filter "label=com.docker.compose.service=app"
}

[ "$#" -ge 1 ] || usage
journey="$1"
shift
EVIDENCE=''
rest=()
while [ "$#" -gt 0 ]; do
    if [ "$1" = --evidence ]; then
        [ "$#" -ge 2 ] || usage
        EVIDENCE="$2"
        shift 2
    else
        rest+=("$1")
        shift
    fi
done
[ -n "$EVIDENCE" ] || usage
case "$EVIDENCE" in /*) ;; *) die 64 "--evidence must be an absolute path" ;; esac
[ -f "$EVIDENCE/.verify/state.env" ] || die 75 "no run in this evidence dir (run up first): $EVIDENCE"
case "$journey" in
    load) cmd_load ${rest[@]+"${rest[@]}"} ;;
    writers) cmd_writers ${rest[@]+"${rest[@]}"} ;;
    *) usage ;;
esac
