# Local Setup

First-time native set-up for a fresh clone or a fresh git worktree, ending in a passing `make test`. Run every command from the repo root.

## Prerequisites

- **PHP 8.4** (Homebrew) with the extensions `composer.json` requires, including `pdo_pgsql`, `swoole`, `soap` and `pcntl`, plus `redis` for `REDIS_CLIENT=phpredis`.
- **Composer**, **Docker** (running), **pnpm**.
- **`ALZ_ADMIN`** set to the absolute path of a local checkout of the `alz-admin` companion frontend repo, with its dependencies installed (`pnpm install` there). It owns the shared Supabase project, so the `make supabase-*` targets run inside it. Without it, the main checkout's `make install`, and `supabase-start`, `supabase-reset`, `supabase-seed-users` and `supabase-functions` fail with `ALZ_ADMIN not set`, and `supabase-stop` and `supabase-status` do nothing. That repo is private, so a standalone clone cannot complete this sequence.

  ```bash
  echo 'export ALZ_ADMIN=/absolute/path/to/alz-admin' >> ~/.zshrc
  source ~/.zshrc
  ```

## Sequence

```bash
make install
make test
```

In the main checkout, `make install` runs these steps, in order, natively (no Sail). A git worktree runs only steps 1–4 (see [Fresh git worktree](#fresh-git-worktree)).

1. Creates `.env` when it is missing (`bin/install-env`). The main checkout's existing `.env` is never overwritten.
2. `composer install`.
3. Registers the `pre-commit` and `pre-push` git hooks (`bin/install-git-hooks`) in the hooks directory git resolves for the checkout (`core.hooksPath` and worktrees honoured, so every worktree of a repo shares one pair). Each hook runs `php artisan git-hooks:<hook>` in the checkout git runs it from. Re-running rewrites them identically. The `pre-push` Pest step runs only the changed tests (`make test-changed`: the tests changed since the merge-base with `origin/HEAD`, plus basename matches for changed `app/` classes), while CI runs the full suite.
4. `php artisan key:generate`, only when `APP_KEY` is blank.
5. `make supabase-start`: starts local Supabase (PostgreSQL on port 54322, which `.env.example` points at) from `$ALZ_ADMIN` and resets a stack it starts from empty with `make db-reset-full`, or skips both when it is already running. It is the only local database: the repo `compose.yaml` runs Redis only, and CI and the verify skill run the pinned Supabase baseline instead, unless a dependent's verify run supplies its own Supabase (see [`database/AGENTS.md`](../../database/AGENTS.md)).
6. `make redis`: starts Redis in Docker.
7. `php artisan migrate`: forward-only migrations against the shared local database. It never resets or wipes it.

Re-running it is safe: it keeps `.env` and `APP_KEY` as they are.

`make lint` and `make test` both run `tests/Unit/Static/GitHooksInstalledTest.php`, which fails with "Run `make install`" when either hook is missing, not executable or does not call artisan, or when `config/git-hooks.php` lacks one of the six checks. Only `CI=true` skips it; the verify containers exclude the `repo-static` group it belongs to.

## Fresh git worktree

A git worktree gets its own database, a compose Postgres on an ephemeral loopback port, instead of the shared Supabase:

```bash
make install
make db-up
make test
```

A worktree does not carry the main checkout's untracked `.env`, so `make install` copies the main checkout's `.env` into the worktree. The copy keeps `API_KEY_ENCRYPTION_SECRET`, the local-bypass secrets and `APP_KEY`, and the worktree shares the main checkout's Redis. When the main checkout has no `.env`, `.env` is created from `.env.example` and gets a new `APP_KEY`.

In a worktree, `make install` stops after the app key: it needs no `ALZ_ADMIN` and runs no Supabase, Redis or migrate. It also rewrites the copied `.env`'s DB keys to `DB_HOST=127.0.0.1`, the closed `DB_PORT=9` and a blank `DB_URL`, and keeps the shared database's port as `SHARED_DB_PORT` for `bin/stack up --shared-supabase`. Raw `php artisan` then reaches no database. Every other line of `.env` is kept.

- `make db-up` starts and migrates the worktree's Postgres alone; `make serve` starts it with the app. Every DB-opening `make` target (and the pre-push hook) runs through `bin/worktree-db`. That gives it the worktree's Postgres, or refuses with `run make db-up` when the Postgres is not up. A `DB_URL`, `DB_HOST` or `DB_PORT` set in your shell, `CI=true` and in-container runs keep their own connection.
- `make db-reset-full` recreates the worktree's Postgres empty and reseeds it, never the shared Supabase (see [`database/AGENTS.md`](../../database/AGENTS.md)). The `supabase-*` targets refuse in a worktree: run them in the main checkout.
- **An older worktree** whose `.env` still names the shared database's 54322: re-run `make install` there to rewrite its DB keys.

The main checkout is the first entry of `git worktree list`.

## Next steps

- **Synthetic data:** `make db-reset-full` resets Supabase (in a worktree, its own Postgres), migrates and seeds the synthetic, production-shaped data (see [`database/AGENTS.md`](../../database/AGENTS.md)). It is destructive on a machine with existing local data. `make supabase-start` runs it whenever it starts a stopped stack, so every fresh boot starts from this seed.
- **Dev server:** `bin/stack up` (`make serve`), or `bin/stack up --shared-supabase` for real sign-in; it prints what it booted and the next commands, and `bin/stack help` lists the rest.
- **Integration tests:** `make test-integration`, after `make db-reset-full`.
