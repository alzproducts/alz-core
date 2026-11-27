# AGENTS.md

Linear team: COR. `${FRONTEND_APP}` resolves from the `FRONTEND_APP` env var in `.claude/settings.local.json`. Orientation, to be checked against the code: [`docs/architecture-overview.md`](docs/architecture-overview.md).

## Production

- Production operations (commands, shells, deploys, variables, data) are human-only; Railway CLI and MCP are denied.
- Production logs are in Grafana: `{app="alz-core",env="production"}`, labels `service` and `level` (`debug`, `info`, `warn`, `error`). Application log records only, `info` and above by default, sensitive context keys redacted. Boot, Swoole and crash output stays in Railway: ask the user for it.
- Prod database queries: hand over paste-ready SQL for the Supabase dashboard SQL editor, never a `psql` or connection-string command, unless the user agrees another route for that query.
- Every value in a local `.env`, the trunk checkout's included, is non-production: copying it into any worktree, test or verify run is safe. A blank vendor secret switches that integration off ([ADR-0015](docs/adr/0015-blank-credentials-switch-integrations-off.md)).

## Local environment

- Native PHP 8.4 for host tooling (`make test`, `make lint`) and Redis in Docker. The main checkout uses the local Supabase PostgreSQL shared with the frontend repo.
- A worktree runs its own compose Postgres (`make db-up`, or `make serve`). Serve, run queued jobs and call the API (`bin/stack curl`, which sends `X-Local-Bypass`) through the worktree's `bin/stack` (`bin/stack help`).
- In a worktree, every host DB route (Pest, the pre-push hook, `make migrate`, seeds, raw `php artisan`) uses the worktree's own database or refuses (raw `php artisan` reaches no database; the rest name `make db-up`). The `supabase-*` targets refuse there: run them in the main checkout.
- Fresh worktree: `make install` first, never `composer install` or an `APP_ENV` override by hand ([local setup](docs/guides/local-setup.md)).
- Start a needed service without asking: `make db-up`, `make redis`, `make supabase-start` (including the `make db-reset-full` it runs on a stopped stack). A manual `make db-reset-full` (wipes local data, reseeds via `dev:seed-fake`; in a worktree, only its own Postgres) still needs the user's go-ahead until COR-377, as do `make supabase-reset` and seeds.
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
