---
name: verify
description: Verify alz-core's HTTP API end to end in a private, throwaway Compose stack per worktree (the production runtime image for Octane and Horizon, its own Postgres and Redis, and a test sidecar), driven with .agents/skills/verify/verify. Use when proving an issue's runtime acceptance criteria, smoke-testing an API journey, or running a targeted Pest file against a migrated, seeded database without touching the developer's Supabase, Redis or Octane.
---

# Verify alz-core

One run is one private Compose stack per worktree ([ADR-0020](../../../docs/adr/0020-verify-runs-prod-image-with-test-sidecar.md)), project `alz-verify-<slug>-<hash8>` (the worktree basename plus a hash of its path). It covers the HTTP API only; the dashboard and storefront live in alz-admin. Journeys live in the feature map, [`features/README.md`](features/README.md). Run every verb with cwd = the worktree under test; nothing here runs PHP, Octane or a queue worker on the host, except the `--qbo-sandbox` token lease.

`up` builds the runtime image from `git archive HEAD`, so uncommitted changes are not in the run: commit first.

**Env:**
- The app, worker and seed load the main checkout's `.env`, found through `git rev-parse --git-common-dir`. A missing file gives a blank run.
- The stack sets its own DB, Redis and app keys, so the env file can never point the run at other services, and fills the keys a run needs when the env file leaves them blank ([ADR-0020](../../../docs/adr/0020-verify-runs-prod-image-with-test-sidecar.md) lists both).
- The network allows outbound calls, so any integration key the env file sets is live. Blank it with `--env-override KEY=` when a journey must not reach the vendor.
- The stack holds no QuickBooks connection, so a QBO call fails with `AuthenticationExpiredException` unless `up` had `--qbo-sandbox`.

## Verbs

`.agents/skills/verify/verify <verb> --evidence <abs dir> [args]`. `--evidence` may sit anywhere before `--`. `<verb> --help` prints one line.

| Verb | Args | Does |
|---|---|---|
| `up` | `[--qbo-sandbox] [--env-override KEY=VALUE]...` | Builds HEAD, boots, migrates and seeds; prints `run_id=`, `base_url=`, `evidence=` |
| `down` | | Removes the run's containers, volumes, networks and images; checks residue |
| `doctor` | | Pass/FAIL report under `doctor/` |
| `http` | `<METHOD> <path> [--data <json>] [--expect <code>]` | One request from inside the app container, as the X-Local-Bypass test user |
| `exec` | `[<service>] [--] <argv>...` | Runs argv in `app` (default), `worker`, `pgsql` or `redis`; stdin passes through |
| `logs` | `[<service>]` | Container stdout, then the `<evidence>/logs/<service>/` files |
| `db` | `<sql>` | Read-only psql as `postgres` in the run's Postgres |
| `test` | `<paths>...` | Pest in the test sidecar, always with `--exclude-group=repo-static` |
| `note` | `<text>` | Appends a `run-notes.md` line |
| `browser` | | Not supported: exits 69 |

Exit codes: 0 ok; 1 check failed (doctor, residue, journey, test, `http` status ≥ 400); 64 usage; 69 not supported; 75 environment not ready. `exec` and `test` instead return the wrapped command's own exit code, so a 64 or 75 from them can come from the command; the `exec` capture's `exit=` line or the `run-notes.md` `test exit=` line, with stderr, tells which.

## Workflow

1. **Up.**
   ```bash
   .agents/skills/verify/verify up --evidence <abs dir> [--qbo-sandbox] [--env-override KEY=VALUE]...
   ```
   - A first build takes several minutes: run `up` in the background.
   - `--qbo-sandbox` lets the stack call the shared QuickBooks sandbox company.
     - It needs host `php` and `composer`, the main checkout's local Supabase and Redis running, and a sandbox connection made there with `quickbooks:connect`.
     - A host loop re-leases the token until `down`; re-lease outcomes go to `run-notes.md`.
     - Never run `quickbooks:lease-access-token` through `exec`: its output is the token.
   - The evidence dir must sit outside every git work tree and hold no `.verify/` from an earlier run.
   - `--env-override KEY=VALUE` is repeatable and replaces one env-file key for the run.
     - It refuses (exit 64) `APP_ENV`, `PATH`, `HOME` and every stack-owned key.
     - A credential key may only be blanked (`KEY=`), never set.
   - Failed exits:
     - Exit 75: Docker is down, or this worktree already has a run up. Run `down` on that run; when its evidence dir is lost, `down --evidence <any new dir>` still removes it, because `down` finds the run's resources by the project label.
     - Exit 1: the message, a log under `build/` or `logs/compose-up.log` names the failure. Run `down` to clean up.

   Done when: `up` exits 0 and prints `base_url=http://127.0.0.1:<port>`: migrated, seeded, the app's `/up` healthy and `horizon:status` passing.

2. **Doctor.** Run `.agents/skills/verify/verify doctor --evidence <dir>` after `up` and whenever anything looks off. It writes `doctor/<stamp>-report.txt`.

   Done when: `doctor` exits 0. Exit 1: the report's `FAIL` lines name what is wrong.

3. **Drive.**
   ```bash
   .agents/skills/verify/verify http --evidence <dir> GET /api/user
   .agents/skills/verify/verify db --evidence <dir> 'select count(*) from shopwired.products'
   .agents/skills/verify/verify exec --evidence <dir> worker -- ps aux
   .agents/skills/verify/verify exec --evidence <dir> -- php artisan about --only=environment --no-interaction
   .agents/skills/verify/verify test --evidence <dir> tests/Feature/Infrastructure/Access/EloquentUserApiKeyRepositoryTest.php
   ```
   - **Requests and reads:**
     - `http` picks its persona by `SUPABASE_LOCAL_TEST_EMAIL`, filled with `dev@alzadmin.test` (admin, approved) when the env file leaves it blank; `/api/user` returns the env file's `EMAIL_PRIMARY` as the email. The bypass secret stays inside the container.
     - `http` prints `status=<code>` and the body.
     - `db` runs in a read-only transaction, so a write exits non-zero.
     - Run artisan commands through `exec -- php artisan <command> --no-interaction`.
   - **Tests:**
     - `test` runs in the `Dockerfile.verify` sidecar (runtime plus dev deps and `tests/`), with the stack's DB and Redis keys, a fresh `APP_KEY` and `API_KEY_ENCRYPTION_SECRET` per call, and no env file, so `phpunit.xml`'s defaults apply.
     - `repo-static` tests need `.git` and never run here.
     - Never use bare Pest or `make test` for verification: they hit the developer's services.
   - **Journeys:**
     - Take ids from a list response, never hard-code them.
     - Follow the matching feature file. When the seed lacks a journey's precondition, report the failure and the reason, never a weakened check.

   Done when: every journey the task names has its proof (§Evidence) or a reported failure with its reason.

4. **Down.** Run `.agents/skills/verify/verify down --evidence <dir>`, also after a failed `up` or journey. It is idempotent, exits 0 with no prior `up`, and removes only resources labelled with this worktree's project.

   Done when: `down` exits 0. Exit 1 with `down FAIL` lines means residue remains: report it, never remove containers by name.

## Evidence

The evidence dir survives `down`.

- `run-notes.md`: one `- <ISO-8601> <text>` line per verb call; add your own with `note`.
- `http/`, `exec/`, `db/`, `test/`, `doctor/`: one `<stamp>-*.txt` capture per call (`test/`: `<stamp>-pest.log`). `http/` captures redact the bypass secret.
- `logs/<service>/`: each PHP service's `storage/logs`, e.g. `logs/app/laravel.log`, `logs/worker/laravel.log`. Also `logs/compose-up.log`, `logs/compose-down.log` and `logs/<stamp>-<service>.txt` from `logs`.
- `build/` holds the image build logs; `.verify/` the run bookkeeping, where `compose.env` and `run.env` hold run secrets, so quote nothing from them.

Proof standard: drive the real route a client calls, capture request and response, and back side effects with a `db` read.
