# Verify runs the prod image from the commit, with a test sidecar

Each verify run boots its own compose project, one per worktree, from an image built from `git archive HEAD` of the worktree, so uncommitted files and `.env*` never reach it, and nothing from the worktree is bind-mounted. The app (Octane) and the worker run that `runtime` image exactly as Railway builds it. The worker is Horizon, started as `horizon --environment=production` so it uses the production supervisor block even though the stack runs `APP_ENV=local` for the local bypass.

Pest, Faker, the dev providers, `tests/` and `phpunit.xml` live only in `Dockerfile.verify`, built `FROM` the run's runtime image and run as a **sidecar** for `test` and for the one-shot that migrates and seeds, since `dev:seed-fake` needs Faker. The seed one-shot shares the Postgres service's network namespace and reaches it over `127.0.0.1`, so the seeder's loopback-only guard stays as it is. The `test` sidecar gets the stack's DB and Redis connection keys and a freshly minted `APP_KEY` and `API_KEY_ENCRYPTION_SECRET`, never the env file, because `phpunit.xml`'s values are not forced and a loaded env would override them. It bypasses the runtime entrypoint, which would refuse a blank `APP_KEY` and cache the config. Its build context is filtered by `Dockerfile.verify.dockerignore`, an allowlist of `tests/`, `phpunit.xml` and `pest.xml`. `Dockerfile` gains no stage, because Railway builds it with no target and so deploys its last stage. A `class_exists` guard in `bootstrap/providers.php` lets the runtime image boot under `APP_ENV=local` without Telescope.

Every run loads the main checkout's `.env`, found through git's common dir with no hard-coded path, including issue-verification runs in detached worktrees. The stack never edits that file; its own keys go in each service's compose `environment:`, which overrides `env_file`. It owns every key that reaches its own Postgres and Redis: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, plus `APP_URL`, `SUPABASE_URL`, `APP_ENV=local` and `PORT=8000`, and it blanks `DB_URL`, `REDIS_URL` and `REDIS_USERNAME`, which would otherwise override its hosts. The host port is ephemeral and `up` reports it as `base_url=`. `APP_KEY`, `API_KEY_ENCRYPTION_SECRET`, `SUPABASE_LOCAL_BYPASS_SECRET` and `SUPABASE_LOCAL_TEST_EMAIL` are filled only when the file leaves them blank: the first three are minted per run, safe because each run's Postgres is new, and the email defaults to `dev@alzadmin.test`. Everything else comes from that file. A missing file gives a blank run that still reaches `doctor` and the `http` verb, and `doctor` reports the missing file on one line. `up --env-override KEY=VALUE` replaces or blanks one key, but never a stack-owned key, and a credential key only to blank it. No local `.env` holds production values: that is a documented AGENTS.md rule (ADR-0015 covers the blank-credential behaviour).

The stack's network allows outbound calls, so journeys reach the integration sandboxes whose keys the main `.env` supplies. No local `.env` holds production values, so an internal network would only block sandbox calls. Postgres sits under a Compose profile: the default runs the stack's own Postgres on the Supabase-baseline image. A second profile, for alz-core booted as a dependency (COR-319), leaves Postgres out and points DB and auth at a caller-supplied Supabase. Each PHP service bind-mounts its own `<evidence>/logs/<service>/` over `storage/logs`, so the log channel stays as `.env.example` sets it.

The verbs follow the cross-repo verify contract (`~/.agents` ADR-0036). alz-core is booted as a dependency by a dependent's `up --with alz-core@<ref>` into a clone, never by an `up --ref`.

## Considered Options

- **Host PHP plus a private Postgres and Redis (today's `verify.sh`, ~920 lines):** rejected. Process management, port plumbing and the isolation guard make up most of the script, a container boundary gives them for free, and host PHP drifts from prod's Swoole and extensions.
- **A `dev` stage `FROM runtime` in `Dockerfile`, which every verify service runs:** rejected. Dev providers would load in the app under test, and any stage after `runtime` changes what Railway deploys.
- **Bind-mounting the worktree:** rejected. The mount brings `.env*` back past `.dockerignore`, and the run would no longer prove a commit.
- **Blank env by default with an opt-in env file:** rejected. Agent runs would not pass the flag, so sandbox ACs could not be proven.
- **Owning only the hosts, ports and DB name:** rejected. The env file's DB and Redis credentials cannot authenticate to the stack's own services, and its `DB_URL` or `REDIS_URL` would override the hosts.
- **Faker in the runtime image for seeding:** rejected, it puts a dev dependency in what production deploys.
- **Widening the seeder's guard to admit the stack's Postgres by name or by an allowlist key:** rejected. Any `.env` could set the key, and a name or private range admits non-loopback databases.
- **`queue:work` as the worker:** rejected, it leaves a gap with prod's Horizon.
- **An `internal: true` network with no route out:** rejected. With no production values in a local `.env`, it protects nothing and blocks sandbox calls.
- **A stack-owned `LOG_CHANNEL=stderr`:** rejected in favour of the logs mount, which keeps the env file's log config.

## Consequences

- `.env.example` sets `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`, and `doctor` fails when either default is not `redis`. `phpunit.xml` and CI keep `sync` and `array`.
- `test` runs only in the sidecar, where no cached config exists, because the entrypoint runs `config:cache` and would pin the app's environment. It always excludes the `repo-static` group, as CI's image job does, because the sidecar has no `.git` and no `*.md`.
- Every run builds an image, so `down` removes the run's images and its residue check covers images, containers, volumes and networks.
- On a Linux Docker host, `up` must create each logs directory writable by uid 33 (`www-data`), because Linux bind mounts keep numeric uids.
- A new integration needs no verifier change: its keys reach the stack from the main `.env`.
