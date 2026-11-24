# AGENTS.md

Linear team: COR. `${FRONTEND_APP}` resolves from the `FRONTEND_APP` env var in `.claude/settings.local.json`. Orientation, to be checked against the code: [`docs/architecture-overview.md`](docs/architecture-overview.md).

## Production

- Production operations (logs, commands, shells, deploys, variables, data) are human-only; Railway CLI and MCP are denied.
- Prod database queries: hand over paste-ready SQL for the Supabase dashboard SQL editor, never a `psql` or connection-string command, unless the user agrees another route for that query.
- Every value in a local `.env`, the trunk checkout's included, is non-production: copying it into any worktree, test or verify run is safe. A blank vendor secret switches that integration off ([ADR-0015](docs/adr/0015-blank-credentials-switch-integrations-off.md)).

## Local environment

- Native PHP 8.4, Octane on Swoole, Redis in Docker, and one local Supabase PostgreSQL shared with the frontend repo and every worktree.
- Fresh worktree: `make install` first, never `composer install` or an `APP_ENV` override by hand ([local setup](docs/guides/local-setup.md)).
- Start a needed service without asking: `make redis`, `make supabase-start` (including the `make db-reset-full` it runs on a stopped stack). A manual `make db-reset-full` (wipes local data, reseeds via `dev:seed-fake`), `make supabase-reset` or seed needs the user's go-ahead.
- Consumer API on a local Octane: `curl -H "X-Local-Bypass: $API_BYPASS_SECRET" "http://127.0.0.1:${API_PORT:-8000}/..."`; setup in `.env.example` (Local Development Auth Bypass).
- Auth rejections log to `storage/logs/security-*.log`, not `laravel.log`. Only `make serve` and the `Octane` run config write `octane.log`.

## Decisions

- Resolve forks by the nearest sibling pattern and flag any new or deviating pattern in the PR. Stop only for irreversible actions, live data or external mutation, or a fork with no precedent and no sensible default.
- A cross-layer contract lives with its consumer, normally `Application/Contracts`, not with the implementation.
- Webhooks respond immediately and process on the queue.
- Octane: jobs compute timestamps in `handle()`, never the constructor.
- Date windows: `startOfMonth()->subMonths($n)`, never a bare `subMonths()`, which leaves gaps at month ends.
- Security controls enforce (fail fast), never only warn.
- Rate limits use Laravel's `Redis::throttle` / `RateLimiter` wherever they cover the need.

## Linting

- Run tools through `make` (`make help`). With several worktrees active run `make lint-sequential`: `make lint` shares `/tmp/alz-lint` across checkouts.
- Run the relevant tests and `make lint` yourself before calling a change done.
- Before any suppression work through `docs/guides/common-linting-errors.md`. If one is still needed, make it a scoped `phpstan.neon` entry rather than an inline ignore, and flag it in the PR for review.
- `phpstan-complexity-baseline.neon`: adjust existing entries only; new code is decomposed to fit.
- Never add an assertion to satisfy PHPStan.
