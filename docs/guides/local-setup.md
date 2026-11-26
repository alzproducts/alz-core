# Local Setup

First-time native set-up for a fresh clone or a fresh git worktree, ending in a passing `make test`. Run every command from the repo root.

## Prerequisites

- **PHP 8.4** (Homebrew) with the extensions `composer.json` requires, including `pdo_pgsql`, `swoole`, `soap` and `pcntl`, plus `redis` for `REDIS_CLIENT=phpredis`.
- **Composer**, **Docker** (running), **pnpm**.
- **`ALZ_ADMIN`** set to the absolute path of a local checkout of the `alz-admin` companion frontend repo, with its dependencies installed (`pnpm install` there). It owns the shared Supabase project, so the `make supabase-*` targets run inside it. Without it, `make install`, `supabase-start`, `supabase-reset`, `supabase-seed-users` and `supabase-functions` fail with `ALZ_ADMIN not set`, and `supabase-stop` and `supabase-status` do nothing. That repo is private, so a standalone clone cannot complete this sequence.

  ```bash
  echo 'export ALZ_ADMIN=/absolute/path/to/alz-admin' >> ~/.zshrc
  source ~/.zshrc
  ```

## Sequence

```bash
make install
make test
```

`make install` runs these steps, in order, natively (no Sail):

1. Creates `.env` when it is missing (`bin/install-env`). An existing `.env` is never overwritten.
2. `composer install`.
3. Registers the `pre-commit` and `pre-push` git hooks (`bin/install-git-hooks`) in the hooks directory git resolves for the checkout (`core.hooksPath` and worktrees honoured, so every worktree of a repo shares one pair). Each hook runs `php artisan git-hooks:<hook>` in the checkout git runs it from. Re-running rewrites them identically.
4. `php artisan key:generate`, only when `APP_KEY` is blank.
5. `make supabase-start`: starts local Supabase (PostgreSQL on port 54322, which `.env.example` points at) from `$ALZ_ADMIN` and resets a stack it starts from empty with `make db-reset-full`, or skips both when it is already running. It is the only local database: the repo `compose.yaml` runs Redis only, and CI and the verify skill run the pinned Supabase baseline instead, unless a dependent's verify run supplies its own Supabase (see [`database/AGENTS.md`](../../database/AGENTS.md)).
6. `make redis`: starts Redis in Docker.
7. `php artisan migrate`: forward-only migrations against the shared local database. It never resets or wipes it.

Re-running it is safe: it keeps `.env` and `APP_KEY` as they are.

`make lint` and `make test` both run `tests/Unit/Static/GitHooksInstalledTest.php`, which fails with "Run `make install`" when either hook is missing, not executable or does not call artisan, or when `config/git-hooks.php` lacks one of the six checks. Only `CI=true` skips it; the verify containers exclude the `repo-static` group it belongs to.

## Fresh git worktree

A fresh git worktree uses the same command: `make install`, then `make test`.

A worktree does not carry the main checkout's untracked `.env`, so `make install` copies the main checkout's `.env` into the worktree. The copy keeps `API_KEY_ENCRYPTION_SECRET`, the local-bypass secrets and `APP_KEY`, which the worktree needs because every checkout shares one local Supabase database and one Redis. When the main checkout has no `.env` (or you run it in the main checkout), `.env` is created from `.env.example` and gets a new `APP_KEY`.

The main checkout is the first entry of `git worktree list`.

## Next steps

- **Synthetic data:** `make db-reset-full` resets Supabase, migrates and seeds the synthetic, production-shaped data (see [`database/AGENTS.md`](../../database/AGENTS.md)). It is destructive on a machine with existing local data. `make supabase-start` runs it whenever it starts a stopped stack, so every fresh boot starts from this seed.
- **Dev server:** `bin/stack up` (or `make serve`, or the `Stack Up` run config) builds the prod runtime image plus `Dockerfile.local` and runs Octane, a Horizon worker, Redis and the stack's own Postgres, migrated and seeded by `dev:seed-fake`, as one compose project per worktree. The worktree is mounted, so route, code and job edits reload without a restart. It prints the app's host port, a free loopback port chosen per worktree; `bin/stack port` prints it again, and `ALZ_STACK_PORT=<n> bin/stack up` pins one. `bin/stack curl GET /api/...` sends a consumer API request as the `X-Local-Bypass` user from inside the app container. `bin/stack logs [<service>]` (`make stack-logs`) follows a service's log, and queued jobs run on its `worker`. `bin/stack down` (`make stack-down`) removes the stack's containers, volumes, network and images.
- **Real sign-in:** `bin/stack up --shared-supabase` runs the app and worker against the shared local Supabase through a relay, taking the database credentials and `SUPABASE_URL` from `.env`. It never migrates or seeds the shared database, and it refuses when that Supabase is not answering. Start it without a reset; `make supabase-start` resets a stopped one. Run `bin/stack down` before switching between the two modes.
- **`.env` edits** apply on the next `bin/stack up`, which recreates only the app and worker and keeps the stack's database.
- **Integration tests:** `make test-integration`, after `make db-reset-full`.
