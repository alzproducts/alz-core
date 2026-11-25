# Verify runs the prod image from the commit, with a test sidecar

Each verify run boots its own compose project, one per worktree, from an image built from `git archive HEAD`, with no code bind-mounted, so uncommitted files and `.env*` never reach it. The app and the worker run the `runtime` image exactly as Railway builds it; the worker is Horizon with the production supervisor block. Pest, Faker, the dev providers and `tests/` live only in `Dockerfile.verify`, a **sidecar** built `FROM` the run's runtime image. `Dockerfile` gains no stage, because Railway deploys its last stage. The sidecar's build context is an allowlist (`Dockerfile.verify.dockerignore`), and a `class_exists` guard in `bootstrap/providers.php` lets the runtime image boot under `APP_ENV=local` without Telescope.

The seed one-shot shares the Postgres service's network namespace, so the seeder's loopback-only guard stays as it is. The `test` sidecar never gets the env file, because `phpunit.xml`'s values are not forced and a loaded env would override them; it bypasses the runtime entrypoint, which would refuse a blank `APP_KEY` and cache the config.

Every run loads the main checkout's `.env`, found through git's common dir with no hard-coded path, including issue-verification runs in detached worktrees. The one exception is a run on a caller-supplied Supabase (below), which never loads it, even from a linked worktree that has one: its integration keys would reach an app and worker on a Postgres the run shares with the caller. The stack never edits that file; its own keys go in each service's compose `environment:`, which overrides `env_file`. It owns every key that reaches its own Postgres and Redis: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, plus `APP_URL`, `SUPABASE_URL`, `APP_ENV=local` and `PORT=8000`, and it blanks `DB_URL`, `REDIS_URL` and `REDIS_USERNAME`, which would otherwise override its hosts. The host port is ephemeral and `up` reports it as `base_url=`. `APP_KEY`, `API_KEY_ENCRYPTION_SECRET`, `SUPABASE_LOCAL_BYPASS_SECRET` and `SUPABASE_LOCAL_TEST_EMAIL` are filled only when the file leaves them blank: the first three are minted per run, safe because each run's Postgres is new, and the email defaults to `dev@alzadmin.test`. `EMAIL_PRIMARY`, the bypass persona's email, and `QUEUE_CONNECTION` and `CACHE_STORE` (`redis`) are filled the same way, so a blank run's bypass resolves and its jobs reach Horizon. Everything else comes from that file. A missing file gives a blank run that still reaches `doctor` and the `http` verb, and `doctor` reports the missing file on one line. `up --env-override KEY=VALUE` replaces or blanks one key, but never a stack-owned key, and a credential key only to blank it. No local `.env` holds production values: that is a documented AGENTS.md rule (ADR-0015 covers the blank-credential behaviour).

Each PHP service bind-mounts its own `<evidence>/logs/<service>/` over `storage/logs`, rather than forcing `LOG_CHANNEL=stderr`, so the env file's log config stays. Outbound calls are allowed, so journeys reach the integration sandboxes. For alz-core booted as a dependency, an override file layered over `compose.yaml` turns off the stack's Postgres and adds a **relay**, whose network namespace the app, worker and seed share; it forwards the caller's two ports on `127.0.0.1`, so `SUPABASE_URL` is the caller's own origin, which its Auth's issuer must match. The caller's Postgres must hold no alz-core seed, and the run never removes what it wrote there. The verbs follow the cross-repo verify contract (`~/.agents` ADR-0036).

## Considered Options

- **Host PHP plus a private Postgres and Redis:** rejected. It drifts from prod's Swoole and extensions, and a container gives the isolation for free.
- **A `dev` stage in `Dockerfile`, or Faker in the runtime image:** rejected. Either changes what production deploys or the app under test.
- **Bind-mounting the worktree:** rejected. The run would no longer prove a commit, and `.env*` returns.
- **Blank env by default:** rejected. Agent runs would not pass the flag, so sandbox ACs could not be proven.
- **Owning only the hosts, ports and DB name:** rejected. The env file's credentials and `DB_URL`/`REDIS_URL` would break or override the stack's own services.
- **Widening the seeder's guard:** rejected. Any `.env` could set the key, and a name or range admits non-loopback databases.
- **Two copies of each service under profiles:** rejected in favour of the override file.
- **`queue:work` as the worker:** rejected. It leaves a gap with prod's Horizon.
- **An internal network:** rejected. It blocks sandbox calls and protects nothing.

## Consequences

- `doctor` fails when `QUEUE_CONNECTION` or `CACHE_STORE` is not `redis`; `phpunit.xml` and CI keep `sync` and `array`.
- `test` runs only in the sidecar and excludes `repo-static`, which needs `.git` and `*.md`. On a caller-supplied Supabase it exits 69.
- App and worker run with `SKIP_DB_CHECK=true`: the entrypoint's `db:show` needs ext-intl, which the runtime image lacks, so the seed's exit 0 gates their start.
- On a Linux Docker host, `up` must create each logs directory writable by uid 33 (`www-data`), because bind mounts keep numeric uids.
