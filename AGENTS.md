# AGENTS.md

Linear team: COR. `${FRONTEND_APP}` resolves from the `FRONTEND_APP` env var in `.claude/settings.local.json`. Orientation, to be checked against the code: [`docs/architecture-overview.md`](docs/architecture-overview.md).

## Production

- Production operations (commands, shells, deploys, variables, data) are human-only, except reads the Read allowlist (`database/agent-read/read-allowlist.php`) permits; Railway CLI and MCP are denied.
- Production logs are in Grafana: `{app="alz-core",env="production"}`, labels `service` and `level` (`debug`, `info`, `warn`, `error`). Application log records only, `info` and above by default, sensitive context keys redacted. Boot, Swoole and crash output stays in Railway: ask the user for it.
- Prod database reads the Read allowlist permits: run `bin/agent-db -c '<SQL>'` yourself, naming columns (`SELECT *` is denied; [guide](docs/guides/agent-production-reads.md)). The human reads each query's audit entry in the Supabase dashboard's Postgres logs (filter `AUDIT`).
- Every other prod database query: hand over paste-ready SQL for the Supabase dashboard SQL editor, never a `psql` or connection-string command, unless the user agrees another route for that query.
- Every value in a local `.env`, the trunk checkout's included, is non-production: copying it into any worktree, test or verify run is safe. A blank vendor secret switches that integration off ([ADR-0015](docs/adr/0015-blank-credentials-switch-integrations-off.md)).

## Local environment

- Native PHP 8.4 for host tooling (`make test`, `make lint`), Redis in Docker, and one local Supabase PostgreSQL shared with the frontend repo and every worktree.
- Serve, run queued jobs and call the API through the worktree's `bin/stack` (`bin/stack help`).
- Fresh worktree: `make install` first, never `composer install` or an `APP_ENV` override by hand ([local setup](docs/guides/local-setup.md)).
- Start a needed service without asking: `make redis`, `make supabase-start` (including the `make db-reset-full` it runs on a stopped stack). A manual `make db-reset-full` (wipes local data, reseeds via `dev:seed-fake`), `make supabase-reset` or seed needs the user's go-ahead.
- Auth rejections log to `storage/logs/security-*.log`, not `laravel.log`. Only the stack app and the product-list bench write `octane.log`.

## Decisions

- Resolve forks by the nearest sibling pattern and flag any new or deviating pattern in the PR. Stop only for irreversible actions, live data or external mutation, or a fork with no precedent and no sensible default.
- A cross-layer contract lives with its consumer, normally `Application/Contracts`, not with the implementation.
- Webhooks respond immediately and process on the queue.
- Octane: jobs compute timestamps in `handle()`, never the constructor.
- Presentation dates (CLI and dev command output, human-readable messages and reports) use British `d/m/Y` (`DD/MM/YYYY`) through the `DateFormat` constants; machine formats stay ISO 8601.
- Date windows: `startOfMonth()->subMonths($n)`, never a bare `subMonths()`, which leaves gaps at month ends.
- Security controls enforce (fail fast), never only warn.
- Rate limits use Laravel's `Redis::throttle` / `RateLimiter` wherever they cover the need.
- A merged feature runs in production once deployed: add no feature toggle or off-by-default env switch unless the user agrees one.

## Linting

- Run tools through `make` (`make help`). With several worktrees active run `make lint-sequential`: `make lint` shares `/tmp/alz-lint` across checkouts.
- Run the relevant tests and `make lint` yourself before calling a change done.
- Before any suppression work through `docs/guides/common-linting-errors.md`. If one is still needed, make it a scoped `phpstan.neon` entry rather than an inline ignore, and flag it in the PR for review.
- `phpstan-complexity-baseline.neon`: adjust existing entries only; new code is decomposed to fit.
- Never add an assertion to satisfy PHPStan.
