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
3. `php artisan key:generate`, only when `APP_KEY` is blank.
4. `make supabase-start`: starts local Supabase (PostgreSQL on port 54322, which `.env.example` points at) from `$ALZ_ADMIN` and resets a stack it starts from empty with `make db-reset-full`, or skips both when it is already running. It is the only local database: the repo `compose.yaml` runs Redis only, and CI and the verify skill run the pinned Supabase baseline instead (see [`database/AGENTS.md`](../../database/AGENTS.md)).
5. `make redis`: starts Redis in Docker.
6. `php artisan migrate`: forward-only migrations against the shared local database. It never resets or wipes it.

Re-running it is safe: it keeps `.env` and `APP_KEY` as they are.

## Fresh git worktree

A fresh git worktree uses the same command: `make install`, then `make test`.

A worktree does not carry the main checkout's untracked `.env`, so `make install` copies the main checkout's `.env` into the worktree. The copy keeps `API_KEY_ENCRYPTION_SECRET`, the local-bypass secrets and `APP_KEY`, which the worktree needs because every checkout shares one local Supabase database and one Redis. When the main checkout has no `.env` (or you run it in the main checkout), `.env` is created from `.env.example` and gets a new `APP_KEY`.

The main checkout is the first entry of `git worktree list`.

## Next steps

- **Synthetic data:** `make db-reset-full` resets Supabase, migrates and seeds a production-shaped products slice (see [`database/AGENTS.md`](../../database/AGENTS.md)). It is destructive on a machine with existing local data. `make supabase-start` runs it whenever it starts a stopped stack, so every fresh boot starts from this seed.
- **Dev server:** `php artisan octane:start --watch`.
- **Integration tests:** `make test-integration`, after `make db-reset-full`.
