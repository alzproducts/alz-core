# Products performance

The product list stays fast while the catalogue is being written to: a mixed stream of list requests keeps a low server-side p99 while the background writers run, and the writers themselves (variation replace, stock mirror) stay within budget of an earlier commit.

## Sub-features

- `products-list-load` sends a paced, mixed product-list load while every background writer runs, and reports the server-side p50/p95/p99/max.
- `products-writer-timing` times the variation-replace and stock-mirror writers as a median of N runs, optionally as a ratio against a baseline run at an earlier commit.

## How to get to it (user POV)

- The dashboard's products screen calls `GET /api/products`, mostly as `per_page=1000&page=1&include=stock`, with paging, filter, sort and search variants.
- Behind it run product webhooks, the stock mirror, supplier sync, cost-price updates, category webhooks, the daily sync's variation replace and the popularity snapshot.

## Driving it with verify.sh

Preconditions:

- A fresh `launch` per journey: each bench verb mutates the seed and refuses a run that already served one (exit 64).
- `doctor` exits 0, including `runtime ok`.
- For a load run, launch with `--octane-workers auto` (production's worker count); the default of 2 measures queueing, not the endpoint.

- **Load run (p99).** `verify.sh bench-load --evidence <dir> [--requests 2000] [--warmup 100] [--rate 2]`.
  - The request mix (70% `per_page=1000&page=1&include=stock`, the rest default page, page 2, filtered, sorted, searched) is derived from the seed and probed for `200` with non-empty `data`; the result's `mix_percent` and `shapes` record it.
  - Every writer in `bench.php writers` runs in the background on its production-shaped schedule; the once-a-day writers fire after warm-up.
  - Latency is server-side: nearest-rank percentiles of the `/api/products` lines `logs/octane.log` gained during the measured window.
  - Prints `p50_ms= p95_ms= p99_ms= max_ms=`, request and status counts, one `writer` line per writer, and `result=<evidence>/bench/load-<stamp>.json`.
  - Exit 1 when a writer errored, the server logged a non-200, or the server line count differs from `--requests`.
  - Takes about `(warmup + requests) / rate` seconds (~18 min at the defaults): run it in the background.
- **Writer timing.** `verify.sh bench-writers --evidence <dir> [--runs 5] [--baseline <baseline evidence dir>]`.
  - Times `EloquentProductRepository::save` of every product with variations (catalogue loaded untimed) and `EloquentProductStockRepository::updateStockLevels`.
  - Prints `<writer> median_ms= runs_ms=` and `result=<evidence>/bench/writers-<stamp>.json`; with `--baseline`, also `<writer> ratio=<head/base> head_median_ms= base_median_ms=` against that dir's latest `bench/writers-*.json`.
- **Baseline at an earlier commit.** This skill's scripts drive any commit that has the repository methods `bench.php` calls.
  1. `git worktree add --detach <abs base worktree> <base sha>` (for an issue, the PR's merge-base).
  2. With cwd = that worktree, run this skill's script by absolute path: `launch`, `bench-writers --evidence <base dir>`, `cleanup`.
  3. With cwd = the head worktree: `launch`, `bench-writers --evidence <head dir> --baseline <base dir>`, `cleanup`.
- **Proof.** The `bench/` result JSON, the `run-notes.md` bench lines, and for a load run `logs/octane.log` plus `logs/bench-writer-*.log`.

## Gotchas

- Run base and head timings back to back on the same idle machine, never concurrently.
- Timings are local-machine numbers on synthetic data: state the machine when quoting them against a production target.
- Writers that fetch from ShopWired in production (product and category webhooks) replay stored rows instead.
