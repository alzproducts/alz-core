# Local Setup

First-time native set-up for a fresh clone, ending in a passing `make test`. Run every command from the repo root, in order.

`make install` is stale Sail tooling; do not use it.

## Prerequisites

- **PHP 8.4** (Homebrew) with the extensions `composer.json` requires, including `pdo_pgsql`, `swoole`, `soap` and `pcntl`, plus `redis` for `REDIS_CLIENT=phpredis`.
- **Composer**, **Docker** (running), **pnpm**.
- **`ALZ_ADMIN`** set to the absolute path of a local checkout of the `alz-admin` companion frontend repo, with its dependencies installed (`pnpm install` there). It owns the shared Supabase project, so every `make supabase-*` target runs inside it and fails with `ALZ_ADMIN not set` without it. That repo is private, so a standalone clone cannot complete this sequence.

  ```bash
  echo 'export ALZ_ADMIN=/absolute/path/to/alz-admin' >> ~/.zshrc
  source ~/.zshrc
  ```

## Sequence

```bash
cp .env.example .env
composer install
php artisan key:generate
make supabase-start
make redis
php artisan migrate
make test
```

- `php artisan key:generate` fills the blank `APP_KEY` that `.env.example` ships with.
- `make supabase-start` starts local Supabase (PostgreSQL on port 54322, which `.env.example` points at) from `$ALZ_ADMIN`.
- `php artisan migrate` creates the Laravel tables; `make test` needs nothing more.

## Next steps

- **Synthetic data:** `make db-reset-full` resets Supabase, migrates and seeds a production-shaped products slice (see [`database/AGENTS.md`](../../database/AGENTS.md)). It is destructive on a machine with existing local data.
- **Dev server:** `php artisan octane:start --watch`.
- **Integration tests:** `make test-integration`, after `make db-reset-full`.
