---
name: verify
description: Verify alz-core's HTTP API end to end in a private, throwaway stack (own Postgres, Redis, Octane and queue worker per run) driven with verify.sh. Use when proving an issue's runtime acceptance criteria, smoke-testing an API journey, or running a targeted Pest file against a migrated, seeded database without touching the developer's Supabase, Redis or Octane.
---

# Verify alz-core

One run is one private stack in the current worktree: Compose project `alz-verify-<runId>` (Postgres + Redis), migrated and seeded with `dev:seed-fake`, Octane on a free loopback port and a run-scoped queue worker. App processes see only `PATH`, `HOME` and generated keys; every external credential is blank, so third-party APIs are unreachable.

- Covers the HTTP API only; the dashboard and storefront live in alz-admin.
- Journeys live in the feature map, [`features/README.md`](features/README.md).
- Run every verb with cwd = the worktree under test.

## Launch

```bash
bash .agents/skills/verify/scripts/verify.sh launch [--evidence <abs dir>] [--env-override K=V]...
```

Output:
- First stdout line `evidence=<dir>`: pass it to every later verb. Without `--evidence`, launch mints one under the run home.
- Ready = exit 0 plus `base_url=http://127.0.0.1:<port>` (migrated, seeded, `/up` 200, worker alive). Expect 1-3 minutes in a fresh worktree.
- `--env-override K=V` replaces one generated key (ports, seed inputs); credential keys and `APP_ENV` are refused (exit 64).

Failures:
- Exit 1 at preflight: the worktree holds a `.env*` file other than a tracked `*.example`, or `bootstrap/cache/config.php`. Use a fresh worktree; never delete a developer's `.env` to get past it.
- Exit 75: Docker is down, or Octane already runs from this worktree.
- Exit 1 at the isolation guard happens before any database write (`run-notes.md` has no `migrate` line).
- Any failure after Compose up keeps the run record: run `cleanup` on its evidence dir.

## Doctor

```bash
bash .agents/skills/verify/scripts/verify.sh doctor --evidence <dir>
```

Run after launch and whenever anything looks off. It prints one `guard`/`credential` line per check and exits 1 on any `FAIL`:
- `guard` lines: each DB and Redis connection resolves to the run's own loopback port, and `octane.port` is the run's HTTP port.
- `credential` lines: each credential's resolved config is blank, the placeholder, or the run-generated value (compared by hash; nothing is printed).

## Drive

```bash
bash .agents/skills/verify/scripts/verify.sh http --evidence <dir> GET /api/user
bash .agents/skills/verify/scripts/verify.sh db --evidence <dir> 'select count(*) from shopwired.products'
```

- `http <METHOD> <path> [--data <json>]` authenticates with the run's `X-Local-Bypass` secret as persona `dev@alzadmin.test` (admin, approved). It prints `status=<code>` and the body; exit 0 on 2xx/3xx, else 1.
- `db <sql>` runs psql in a read-only transaction in the run's Postgres; use it to prove stored state.
- Take ids from a list response, never hard-code them.
- Follow the matching feature file; when the seed lacks a journey's precondition, report the failure and the reason, never a weakened check.

## Evidence

The evidence dir (caller's `--evidence`, else the one launch minted) is outside every work tree and survives cleanup.

- `run-notes.md`: one `- <ISO-8601> <text>` line per step and call; add your own with `note`.
- `http/<stamp>-<method>.txt`: request (bypass secret redacted), status, response headers and body.
- `logs/`: composer, migrate, seed, Octane, worker and Pest output.
- `run.env`, `verify-state.json`, `compose/`: run bookkeeping; `run.env` holds run secrets, quote nothing from it.
- Proof standard: drive the real route a client calls, capture request and response, and back side effects with a `db` read.

## Cleanup

```bash
bash .agents/skills/verify/scripts/verify.sh cleanup --evidence <dir>
```

- Always run it, also after a failed launch or journey; it is idempotent.
- It stops only what the run recorded and removes the run's own Compose project and volumes; the evidence dir stays.
- Exit 1 with `cleanup FAIL` lines means residue (a container, volume or listener on a run port) remains: report it, never kill by name.

## Helpers

`scripts/verify.sh` is the only helper (bash 3.2-safe); `scripts/compose.yaml` defines its private stack.

| Verb | Args | Does |
|---|---|---|
| `launch` | `[--evidence <abs dir>] [--env-override K=V]...` | Private stack, guard, migrate, seed, Octane, worker |
| `doctor` | `--evidence <dir>` | Isolation guard plus credential check |
| `http` | `--evidence <dir> <METHOD> <path> [--data <json>]` | Bypass-authenticated request, captured under `http/` |
| `db` | `--evidence <dir> <sql>` | Read-only psql in the run's Postgres |
| `test` | `--evidence <dir> <pest path>` | Targeted Pest runner |
| `note` | `--evidence <dir> <text>` | Appends a `run-notes.md` line |
| `cleanup` | `--evidence <dir>` | Tears down the run and checks residue |

- **Invocation:** `bash .agents/skills/verify/scripts/verify.sh <verb> [args]`; an extracted copy runs as `bash <extracted dir>/scripts/verify.sh <verb> [args]`, cwd = the worktree.
- **Targeted Pest runner:** `verify.sh test --evidence <dir> <path>` after `launch`, e.g. `verify.sh test --evidence <dir> tests/Unit/Static/StaleSeedWordingTest.php`. It runs Pest with `APP_ENV=testing` against the run's DB and Redis. Never use bare Pest or `make test` for verification: `phpunit.xml` sets no DB or Redis keys, so those hit the developer's services. In a worktree without `.env`, each test reports a `fopen(.../.env)` warning from `bypass-finals`; exit 0 is still a pass.
- **Exit codes:** 0 ok; 1 check failed (guard, credential, residue, journey, test); 64 usage; 75 environment not ready.
