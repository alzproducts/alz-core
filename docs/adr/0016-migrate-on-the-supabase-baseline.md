---
status: proposed
---

# Migrate on the Supabase baseline

Production migrates as hosted Supabase's `postgres` role, which is not a superuser and cannot create objects in `auth`. CI on plain Postgres as a superuser passed a release that then failed every production web deploy (COR-290). So every database alz-core migrates outside production runs the **Supabase baseline**: Supabase's Postgres image plus the GoTrue image's migrations and the Realtime image's tenant migrations, pinned in one file to the hosted project's versions, migrated as `postgres`. Local development runs the same images through the Supabase CLI. No migration creates a **Supabase look-alike**; a missing Supabase object fails the migration (COR-291).

## Considered Options

- **Supabase CLI stack in CI and verify:** rejected. It needs a private project directory, pins by CLI release, and its fixed ports clash with parallel runs.
- **Supabase Postgres image alone:** rejected. It lacks GoTrue's tables and Realtime's `realtime` schema objects, so look-alikes would stay.
- **Hosted Supabase branch per run:** rejected for cost, secrets in CI and network dependence.
- **A `postgres NOLOGIN` role in verify:** rejected. It hides the mismatch.

## Consequences

- Bump the pins when the hosted project upgrades: `bin/supabase-hosted-version` prints the Postgres version, and a re-link in `${FRONTEND_APP}` records Auth's in `supabase/.temp/gotrue-version`, and Realtime's is the newest release whose newest tenant migration matches production's `realtime.schema_migrations`.
- A passing local migrate proves little, because Laravel never re-runs a recorded migration: CI's fresh migrate is the gate.
- The one exception outside production and local development: a dependent's verify run may supply its own Supabase ([ADR-0020](0020-verify-runs-prod-image-with-test-sidecar.md)).
