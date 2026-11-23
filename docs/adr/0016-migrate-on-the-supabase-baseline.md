---
status: proposed
---

# Migrate on the Supabase baseline

Production migrates as hosted Supabase's `postgres` role, which is not a superuser and cannot create objects in `auth`. Release #1076 passed CI on plain Postgres as a superuser and then failed every production web deploy on `permission denied for schema auth` (COR-290). So every database alz-core migrates outside production (CI, the verify stack, the offline dev image, the production-like compose) runs the **Supabase baseline**: Supabase's own Postgres image plus the Supabase Auth (GoTrue) image's migrations, pinned in one file to the hosted project's versions, with migrations run as `postgres`. Local development already runs the same images through the Supabase CLI, once `${FRONTEND_APP}` runs major 17. No migration creates a **Supabase look-alike**; a missing Supabase object fails the migration (COR-291).

## Considered Options

- **Supabase CLI stack in CI and verify:** rejected. It needs a Supabase project directory (`${FRONTEND_APP}`'s is private), pins images by CLI release rather than by the hosted project, and its fixed ports clash with parallel private verify runs.
- **Supabase Postgres image alone:** rejected. It has Supabase's roles but not GoTrue's tables, so `auth.mfa_factors` look-alikes would stay.
- **Hosted Supabase branch per run:** rejected for cost, secrets in CI and network dependence.
- **A `postgres NOLOGIN` role in the verify stack:** rejected. It hides the mismatch rather than removing it.

## Consequences

- CI runs Postgres 17, the hosted major after COR-293.
- Bump the pinned versions when the hosted project upgrades: `bin/supabase-hosted-version` prints the hosted Postgres version, and a re-link in `${FRONTEND_APP}` records the Auth version in `supabase/.temp/gotrue-version`.
- A local migrate that passes proves little: Laravel never re-runs a recorded migration, so CI's fresh migrate is the gate.
- A dependent's verify run may boot alz-core against a Supabase the dependent supplies, usually a Supabase CLI stack (`verify up --supabase-url … --supabase-db-url …`, [ADR-0020](0020-verify-runs-prod-image-with-test-sidecar.md)). That is the one exception to the baseline outside production and local development; alz-core's own verify runs keep it.
