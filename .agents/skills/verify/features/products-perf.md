# Products performance

The product list stays fast while the catalogue is being written to: a mixed stream of list requests keeps a low server-side p99 while the background writers run, and the writers themselves (variation replace, stock mirror) stay within budget of an earlier commit.

## Sub-features

- `products-list-load` sends a paced, mixed product-list load while every background writer runs, and reports the server-side p50/p95/p99/max.
- `products-writer-timing` times the variation-replace and stock-mirror writers as a median of N runs, optionally as a ratio against a baseline run at an earlier commit.

## How to get to it (user POV)

- The dashboard's products screen calls `GET /api/products`, mostly as `per_page=1000&page=1&include=stock`, with paging, filter, sort and search variants.
- Behind it run product webhooks, the stock mirror, supplier sync, cost-price updates, category webhooks, the daily sync's variation replace and the popularity snapshot.

## Driving it with verify

`features/products-perf.sh` drives both journeys against an `up` run through `verify exec`, with cwd = the worktree under test; `products-perf.php` is its in-container side.

Preconditions:

- A fresh `up` per journey: each journey mutates the seed, and the script refuses a run that already served one (exit 64).
- `doctor` exits 0.
- For a load run, bring the run up with `--env-override OCTANE_WORKERS=<production's count>`: the stack's default of 4 can measure queueing, not the endpoint.

- **Load run (p99).** `.agents/skills/verify/features/products-perf.sh load --evidence <dir> [--requests 2000] [--warmup 100] [--rate 2]`.
  - The request mix (70% `per_page=1000&page=1&include=stock`, the rest default page, page 2, filtered, sorted, searched) is derived from the seed and probed for `200` with non-empty `data`; the result's `mix_percent` and `shapes` record it.
  - Every writer in `products-perf.php writers` runs in the worker container on its production-shaped schedule; the once-a-day writers fire after warm-up.
  - Latency is server-side: nearest-rank percentiles of the `/api/products` lines the app container's stdout gained during the measured window.
  - Prints `p50_ms= p95_ms= p99_ms= max_ms=`, request and status counts, one `writer` line per writer, and `result=<evidence>/bench/load-<stamp>.json`.
  - Exit 1 when a writer errored, the server logged a non-200, the server line count differs from `--requests`, or any request timed out client-side (`FAIL saturated`).
- **Reading a load run.** `FAIL saturated` means the commit cannot serve `--rate` on this machine: report it as that, never the percentiles printed beside it (they mix backlog lines from earlier requests). A run takes about `(warmup + requests) / rate` seconds (~18 min at the defaults): run it in the background.
- **Writer timing.** `.agents/skills/verify/features/products-perf.sh writers --evidence <dir> [--runs 5] [--baseline <baseline evidence dir>]`.
  - Times `EloquentProductRepository::save` of every product with variations (catalogue loaded untimed) and `EloquentProductStockRepository::updateStockLevels`.
  - Prints `<writer> median_ms= runs_ms=` and `result=<evidence>/bench/writers-<stamp>.json`; with `--baseline`, also `<writer> ratio=<head/base> head_median_ms= base_median_ms=` against that dir's latest `bench/writers-*.json`.
- **Baseline at an earlier commit.** The script drives any commit whose runtime image boots in this stack and has the repository methods `products-perf.php` calls.
  1. `git worktree add --detach <abs base worktree> <base sha>` (for an issue, the PR's merge-base).
  2. With cwd = that worktree, run this worktree's `verify` and `products-perf.sh` by absolute path: `up --evidence <base dir>`, `writers --evidence <base dir>`, `down --evidence <base dir>`.
  3. With cwd = the head worktree: `up --evidence <head dir>`, `writers --evidence <head dir> --baseline <base dir>`, `down --evidence <head dir>`.
- **Proof.** The `bench/` result JSON, the `run-notes.md` bench lines, and for a load run `logs/app/bench/load-<stamp>/` (Octane stdout, request mix) plus `logs/worker/bench/load-<stamp>/` (one log and result per writer).

## Gotchas

- Run base and head timings back to back on the same idle machine, never concurrently. One worktree holds one run at a time.
- Timings are local-machine numbers on synthetic data: state the machine when quoting them against a production target.
- The run's Postgres is the Supabase baseline (`database/baseline/`), with Compose defaults rather than local Supabase's tuning: a load run at the defaults can saturate on a slow machine. Compare base and head on this stack, never against numbers taken elsewhere.
- Writers that fetch from ShopWired in production (product and category webhooks) replay stored rows instead.
