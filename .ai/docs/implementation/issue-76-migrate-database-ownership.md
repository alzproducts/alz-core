# Implementation Log: Supabase to Laravel Database Migration

**GitHub Issue**: #76
**Plan Document**: .ai/docs/plans/supabase-to-laravel-migration.md
**Status**: In Progress
**Started**: 2025-12-23
**Completed**: —

## Overview

Transfer database schema ownership from Supabase migrations to Laravel while keeping Supabase Auth (MFA, custom JWT claims, RLS) unchanged. Enables Eloquent ORM for backend data access.

## Decision Log

### 2025-12-23 (Phase 0 - Security)
- **Decision**: Place `AuthenticatedUser` value object in Domain layer
- **Why**: Pure value object with no framework dependencies, used throughout app
- **Tradeoff**: None - clean CA compliance

- **Decision**: Place `SupabaseJwtParser` in Presentation layer (not Infrastructure)
- **Why**: JWT parsing is HTTP auth concern, not external API concern. Keeps dependency direction correct (Presentation can't depend on Infrastructure).
- **Tradeoff**: Slightly less intuitive location, but architecturally correct

- **Decision**: Email is required claim (not optional)
- **Why**: Security - can't have unknown users accessing the system
- **Tradeoff**: None - this was a bug fix

- **Decision**: Skip `auth.jwt` middleware group (only create `auth.supabase`)
- **Why**: YAGNI - no current use case for endpoints allowing unapproved users
- **Tradeoff**: Would need to add later if requirements change

- **Decision**: Skip dedicated tests for `EnsureUserApprovedMiddleware`
- **Why**: Per TestingStrategy.md - Presentation layer doesn't need coverage targets. Auth will get integration coverage when testing actual protected routes.
- **Tradeoff**: Relies on feature tests later; no isolated middleware tests

### 2025-12-23 (Phase 1 - Database Config)
- **Decision**: Add multi-schema search_path: `public,access,config,utils`
- **Why**: Matches existing Supabase schema organization per plan document
- **Tradeoff**: None - required for multi-schema queries

## Deviations from Plan

- `auth.jwt` middleware not created (plan suggested it, but YAGNI applies)

## Blockers / Open Questions

- [x] MFA bypass prevention (AAL2 check) - Done in previous session
- [x] JWT custom claims extraction - Done in previous session
- [x] User approval middleware - Done this session
- [ ] Phase 2: Adoption migrations (15 files)
- [ ] Phase 3: Eloquent models
- [ ] Phase 4: Domain layer integration
- [ ] Phase 5: Service provider registration
- [ ] Phase 6: Cutover & verification

## Technical Notes

**Middleware Group**: `auth.supabase` combines:
1. `ValidateSupabaseJwtMiddleware` - JWT validation + MFA (AAL2) check
2. `EnsureUserApprovedMiddleware` - Approval check

**search_path Clarification**: The PostgreSQL search_path primarily helps with:
- Unqualified function calls (like `auth.uid()` in RLS policies)
- Type resolution
- Eloquent models still need explicit schema prefix (e.g., `'access.roles'`)

## PR Notes

### What
Phase 0 (Security) + Phase 1 (Database Config) of Supabase to Laravel migration.

### Why
Enable Eloquent ORM for backend data access while keeping Supabase Auth unchanged.

### Key Decisions
- `AuthenticatedUser` in Domain layer (pure value object)
- `SupabaseJwtParser` in Presentation layer (HTTP auth concern)
- Single `auth.supabase` middleware group (YAGNI on `auth.jwt`)
- Multi-schema search_path for PostgreSQL

### Testing
- All linting passes (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- All tests pass (1949 tests)
- JWT middleware has thorough tests from previous session
