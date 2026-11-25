---
name: verify
description: Verify alz-core's HTTP API end to end in a private, throwaway Compose stack per worktree (the production runtime image for Octane and Horizon, its own Postgres and Redis, and a test sidecar), driven with .agents/skills/verify/verify. Use when proving an issue's runtime acceptance criteria, smoke-testing an API journey, or running a targeted Pest file against a migrated, seeded database without touching the developer's Supabase, Redis or Octane.
---

# Verify alz-core

One run is one private Compose stack per worktree ([ADR-0020](../../../docs/adr/0020-verify-runs-prod-image-with-test-sidecar.md)), project `alz-verify-<slug>-<hash8>` (the worktree basename plus a hash of its path). A run may hold one base run, built from an ancestor commit, with the same env file (§Base run). It covers the HTTP API only; the dashboard and storefront live in alz-admin. Journeys live in the feature map, [`features/README.md`](features/README.md). Run every verb with cwd = the worktree under test; nothing here runs PHP, Octane or a queue worker on the host.

`up` builds the runtime image from `git archive HEAD`, so uncommitted changes are not in the run: commit first.

**Env:**
- The app, worker and seed load the main checkout's `.env`, found through `git rev-parse --git-common-dir`. A missing file gives a blank run, as does a caller-supplied Supabase (§Caller-supplied Supabase).
- The stack sets its own DB, Redis and app keys, so the env file can never point the run at other services, and fills the keys a run needs when the env file leaves them blank ([ADR-0020](../../../docs/adr/0020-verify-runs-prod-image-with-test-sidecar.md) lists both).
- The network allows outbound calls, so any integration key the env file sets is live. Blank it with `--env-override KEY=` when a journey must not reach the vendor.
- Single-quote an env-file value that holds `$`, or `up` refuses it (step 1).

## Verbs

`.agents/skills/verify/verify <verb> --evidence <abs dir> [args]`. `--evidence` may sit anywhere before `--`. `<verb> --help` prints one line.

| Verb | Args | Does |
|---|---|---|
| `up` | `[--env-override KEY=VALUE]... [--supabase-url <origin> --supabase-db-url <url>]` | Builds HEAD, boots, migrates and seeds; prints `run_id=`, `base_url=`, `evidence=` |
| `down` | | Removes the run's and its base run's containers, volumes, networks and images; checks residue |
| `doctor` | | Pass/FAIL report under `doctor/` |
| `http` | `<METHOD> <path> [--data <json>] [--expect <code>]` | One request from inside the app container, as the X-Local-Bypass test user |
| `exec` | `[<service>] [--] <argv>...` | Runs argv in `app` (default), `worker`, `pgsql` or `redis`; stdin passes through |
| `logs` | `[<service>]` | Container stdout, then the `<evidence>/logs/<service>/` files |
| `db` | `<sql>` | Read-only psql as `postgres` in the run's Postgres |
| `test` | `[--all-groups] <paths>...` | Pest in the test sidecar, excluding CI's groups (`integration`, `seed-command`, `list-parity`) and `repo-static`; `--all-groups` excludes only `repo-static`; `--group` and `--exclude-group` exit 64 |
| `baseline` | `[--ref <SHA>] [--time-limit <seconds>]` | Boots the base run; prints `baseline.sha=`, `baseline.evidence=`, `baseline.entry=`, `baseline.cwd=` |
| `parity` | `[--ignore <field path>]... [--expect <code>] GET <path> [GET <path>]...` | Sends each GET to both runs and compares status and body |
| `note` | `<text>` | Appends a `run-notes.md` line |
| `browser` | | Not supported: exits 69 |

Exit codes: 0 ok; 1 check failed (doctor, residue, journey, test, `http` status ≥ 400); 64 usage; 69 not supported; 75 environment not ready. `exec` and `test` instead return the wrapped command's own exit code, so a 64 or 75 from them can come from the command; the `exec` capture's `exit=` line or the `run-notes.md` `test exit=` line, with stderr, tells which.

## Workflow

1. **Up.**
   ```bash
   .agents/skills/verify/verify up --evidence <abs dir> [--env-override KEY=VALUE]...
   ```
   - A first build takes several minutes: run `up` in the background.
   - The evidence dir must sit outside every git work tree and hold no `.verify/` from an earlier run.
   - `--env-override KEY=VALUE` is repeatable and replaces one env-file key for the run.
     - It refuses (exit 64) `APP_ENV`, `PATH`, `HOME` and every stack-owned key.
     - A credential key may only be blanked (`KEY=`), never set.
   - Exit 75: Docker is down, or this worktree already has a run up. Run `down` on that run; when its evidence dir is lost, `down --evidence <any new dir>` still removes it, because `down` finds the run's resources by the project label.
   - Exit 75, before any build:
     - The env file the run loads has an unquoted or double-quoted value with a `$` that is not a `${NAME}` reference to a key an earlier line sets. The message lists every such key. Only a human edits that file.
     - HEAD has no `Dockerfile.verify`, or no `db:ping` in its `docker-entrypoint.sh`: merge `develop` into the branch.
   - Exit 1: a build log under `build/` or `logs/compose-up.log` names the failure. Run `down` to clean up.

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
     - `repo-static` tests need `.git` and never run here, with or without `--all-groups`.
     - The `run-notes.md` `test` line shows the exclusions applied. An excluded file passed beside selected ones is omitted silently; when nothing is selected Pest exits 1. `--filter` still passes.
     - Never use bare Pest or `make test` for verification: they hit the developer's services.
   - **Journeys:**
     - Take ids from a list response, never hard-code them.
     - Follow the matching feature file. When the seed lacks a journey's precondition, report the failure and the reason, never a weakened check.

   Done when: every journey the task names has its proof (§Evidence) or a reported failure with its reason.

4. **Down.** Run `.agents/skills/verify/verify down --evidence <dir>`, also after a failed `up` or journey. It is idempotent, exits 0 with no prior `up`, and removes only resources labelled with this worktree's project.

   Done when: `down` exits 0. Exit 1 with `down FAIL` lines means residue remains: report it, never remove containers by name.

## Base run

`baseline` builds an ancestor commit with `git archive` from this checkout's objects and boots it on its own project, `<project>-base`, with its own Postgres and Redis, the env file the head run recorded and the keys filled or overridden for it. The journey and what a criterion must name: [`features/parity.md`](features/parity.md).

**`baseline`:**
- Needs `jq` on the host. A first build takes several minutes: run it in the background.
- `--ref` defaults to HEAD's merge base with the fetched `origin/develop`. HEAD is the commit `up` built.
- `--time-limit` is the boot's limit in seconds, 1 to 900, by default 900.
- Its evidence dir is `<evidence>/baseline/`. Drive the base run's other verbs through the printed `baseline.entry`, with `baseline.cwd` as cwd and `baseline.evidence` as evidence.
- One base run per head run: the live base run's SHA reprints its values.

| Exit | When |
|---|---|
| 64 | The ref is HEAD, is not an ancestor of HEAD, or resolves to no commit; or the evidence dir is the base run's |
| 69 | The run is on a caller-supplied Supabase |
| 75 | No run is up, or `origin/develop` cannot be fetched |
| 75 | The base commit has no `Dockerfile.verify`, or no `db:ping` in its `docker-entrypoint.sh` |
| 75 | The seed code differs between the commits; the message names it |
| 75 | A base run is up at another SHA, which is left up |
| 75 | A boot stage (archive, build, boot, migrate, seed) failed, or the time limit expired. The message names the stage and its log, or the limit, and the base run is removed |

**`parity`:**
- Ignores only the fields the caller passes with `--ignore`; there is no built-in list. A path is like `.data[].title`, `.data[0]` or `.["odd key"]`.
- Prints `match`, `difference` (with the field path) or `failure` lines. Any line but `match` makes it exit 1.
- A status of 400 or above fails unless `--expect` names it, and so does any status other than the one `--expect` names.
- Exits 64 for any method but `GET` and sends nothing, 69 on a caller-supplied Supabase, and 75 with no run or no base run up.

`down` on the head run's evidence dir, or on a new one, removes both runs; on `baseline.evidence` it removes only the base run.

## Caller-supplied Supabase

For a dependent that boots alz-core beside its own local Supabase, so both share one Postgres and one Auth ([ADR-0020](../../../docs/adr/0020-verify-runs-prod-image-with-test-sidecar.md)):

```bash
.agents/skills/verify/verify up --evidence <abs dir> \
  --supabase-url http://127.0.0.1:<api port> \
  --supabase-db-url postgresql://postgres:<password>@127.0.0.1:<db port>/postgres
```

`up` starts no Postgres and no `auth-migrate`. A `relay` container forwards the caller's two ports, and `up` migrates and seeds the caller's Postgres.

**The caller provides:**
- A running Supabase with its own migrations applied and its users created before `up`.
- A Postgres without alz-core's seed. A second `up` on the same Postgres exits 1 naming `logs/seed/seed.log` and leaves the seeded rows as they were. `down` never removes rows from the caller's Postgres.
- aal2 tokens for its own requests to `base_url`: `GET /api/user` with an aal1 token returns 403 "MFA verification required.".

**Blank run:** this mode never loads the main checkout's `.env`, even from a linked worktree, so integrations are off. `--env-override` still applies.

**Refusals**, all before any Docker call. A message names the flag, with `host:port` where that is safe to print, never the URL:

| Exit | When |
|---|---|
| 64 | Only one of the flags, a flag given twice, or `--flag=value` |
| 64 | A host other than `127.0.0.1` or `localhost`, spelled exactly (`[::1]` is refused on every port) |
| 64 | A port that is missing, has a leading zero, exceeds 65535, is 8000, 54321 or 54322, or is the same for both flags |
| 64 | `--supabase-url` that is not a bare `http://<host>:<port>` |
| 64 | `--supabase-db-url` that is not `postgresql://`, has a user or database other than `postgres`, a password outside `[A-Za-z0-9._~-]`, or a query string |
| 64 | An issuer other than `<supabase-url>/auth/v1` (both are named): pass the host spelling that Supabase reports |
| 75 | Nothing answers at either address, or no readable issuer at `--supabase-url` |

Both probes connect to `127.0.0.1` whichever spelling was passed. After the build, `up` exits 75 naming `logs/compose-up.log` when the relay is not healthy.

**Verbs in this mode:**

| Verb | Difference |
|---|---|
| `doctor` | Expects the database at `127.0.0.1:<caller db port>`; FAILs on an unhealthy relay |
| `db` | Read-only `psql` in the relay, against the caller's Postgres |
| `test` | Exits 69 |
| `baseline`, `parity` | Exit 69 |
| `exec`, `logs` | Exit 69 for `pgsql` and `auth-migrate`; accept `relay` |
| `down` | Removes this run's own resources only; the caller's Supabase keeps running |

Every verb that needs the app running also needs the relay running, and exits 75 naming it when it is gone. `http` keeps the X-Local-Bypass persona.

## Evidence

The evidence dir survives `down`.

- `run-notes.md`: one `- <ISO-8601> <text>` line per verb call; add your own with `note`.
- `http/`, `exec/`, `db/`, `test/`, `doctor/`: one `<stamp>-*.txt` capture per call (`test/`: `<stamp>-pest.log`). `http/` captures redact the bypass secret.
- `parity/`: one `<stamp>-verdict.txt` per `parity` call. `baseline/`: the base run's evidence, laid out like this one.
- `logs/<service>/`: each PHP service's `storage/logs`, e.g. `logs/app/laravel.log`, `logs/worker/laravel.log`. Also `logs/compose-up.log`, `logs/compose-down.log` and `logs/<stamp>-<service>.txt` from `logs`.
- `logs/seed/seed.log`: the seed's output, on a caller-supplied Supabase only.
- `build/` holds the image build logs; `.verify/` the run bookkeeping, where `compose.env` and `run.env` hold run secrets, so quote nothing from them.

Proof standard: drive the real route a client calls, capture request and response, and back side effects with a `db` read.
