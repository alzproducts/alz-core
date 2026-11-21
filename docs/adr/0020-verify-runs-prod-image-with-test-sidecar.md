# Verify runs the prod image from the commit, with a test sidecar

Each verify run boots its own compose project, `alz-verify-<worktree>`, from an image built from `git archive HEAD` of the worktree, so uncommitted files and `.env*` never reach it, and nothing from the worktree is bind-mounted. The app (Octane) and the worker run that `runtime` image exactly as Railway builds it. The worker is Horizon, started as `horizon --environment=production` so it uses the production supervisor block even though the stack runs `APP_ENV=local` for the local bypass.

Pest, Faker, the dev providers, `tests/` and `phpunit.xml` live only in `Dockerfile.verify`, built `FROM` the run's runtime image and run as a **sidecar** for `test`. Its build context is filtered by `Dockerfile.verify.dockerignore`, an allowlist of `tests/`, `phpunit.xml` and `pest.xml`. `Dockerfile` gains no stage, because Railway builds it with no target and so deploys its last stage. A `class_exists` guard in `bootstrap/providers.php` lets the runtime image boot under `APP_ENV=local` without Telescope.

Every run loads the main checkout's `.env`, found through git's common dir with no hard-coded path, including issue-verification runs in detached worktrees. The stack owns only the per-run keys: the DB and Redis hosts and ports, the DB name, `APP_URL`, `OCTANE_PORT`, `SUPABASE_URL`, plus `APP_ENV=local`. Everything else comes from that file. A missing file gives a blank run, `doctor` reports it on one line, and `up --env-override KEY=VALUE` replaces or blanks one key. No local `.env` holds production values: that is a documented AGENTS.md rule (ADR-0015 covers the blank-credential behaviour).

The stack's network allows outbound calls, so journeys reach the integration sandboxes whose keys the main `.env` supplies. No local `.env` holds production values, so an internal network would only block sandbox calls. Postgres sits under a Compose profile: the default runs the stack's own Postgres on the Supabase-baseline image. A second profile, for alz-core booted as a dependency (COR-319), leaves Postgres out and points DB and auth at a caller-supplied Supabase. Each PHP service bind-mounts its own `<evidence>/logs/<service>/` over `storage/logs`, so the log channel stays as `.env.example` sets it.

The verbs follow the cross-repo verify contract (`~/.agents` ADR-0036). alz-core is booted as a dependency by a dependent's `up --with alz-core@<ref>` into a clone, never by an `up --ref`.

## Considered Options

- **Host PHP plus a private Postgres and Redis (today's `verify.sh`, ~920 lines):** rejected. Process management, port plumbing and the isolation guard make up most of the script, a container boundary gives them for free, and host PHP drifts from prod's Swoole and extensions.
- **A `dev` stage `FROM runtime` in `Dockerfile`, which every verify service runs:** rejected. Dev providers would load in the app under test, and any stage after `runtime` changes what Railway deploys.
- **Bind-mounting the worktree:** rejected. The mount brings `.env*` back past `.dockerignore`, and the run would no longer prove a commit.
- **Blank env by default with an opt-in env file:** rejected. Agent runs would not pass the flag, so sandbox ACs could not be proven.
- **`queue:work` as the worker:** rejected, it leaves a gap with prod's Horizon.
- **An `internal: true` network with no route out:** rejected. With no production values in a local `.env`, it protects nothing and blocks sandbox calls.
- **A stack-owned `LOG_CHANNEL=stderr`:** rejected in favour of the logs mount, which keeps the env file's log config.

## Consequences

- `.env.example` sets `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`, and `doctor` fails when either default is not `redis`. `phpunit.xml` and CI keep `sync` and `array`.
- `test` runs only in the sidecar, where no cached config exists, because the entrypoint runs `config:cache` and would pin the app's environment.
- Every run builds an image, so `down` removes the run's images and its residue check covers images, containers, volumes and networks.
- On a Linux Docker host, `up` must create each logs directory writable by uid 33 (`www-data`), because Linux bind mounts keep numeric uids.
- A new integration needs no verifier change: its keys reach the stack from the main `.env`.
