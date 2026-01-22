# Implementation Log: Issue #142 - Presentation Layer Refactoring

## Overview
Reorganize Presentation layer for scalability with feature-based structure.

**Plan**: `.ai/plans/2026-01-23_142-presentation-layer-refactoring.md`

---

## Decision Log

| Decision | Rationale | Date |
|----------|-----------|------|
| Single PR for all changes | Namespace changes interconnected, easier to review holistically | 2026-01-23 |
| Jobs organized by integration | Feature-based grouping (Shopwired/, Mixpanel/, Feeds/, Linnworks/) improves discoverability | 2026-01-23 |

---

## Progress

### ✅ Stage 0: Jobs Directory Refactoring (Pre-work)
- Moved 12 jobs into feature subdirectories
- Updated all namespace imports (commands, providers, routes, tests)
- Moved 5 test files to matching structure

### Stage 1: Extract Schedule Definitions
- [ ] Create `app/Providers/Schedule/` directory
- [ ] `ShopwiredScheduleServiceProvider`
- [ ] `AdsScheduleServiceProvider`
- [ ] `FeedsScheduleServiceProvider`
- [ ] `LinnworksScheduleServiceProvider`
- [ ] `MixpanelScheduleServiceProvider`
- [ ] Register in `bootstrap/providers.php`
- [ ] Clean up `routes/console.php`

### Stage 2: Auth Consolidation
- [ ] Move `ValidateSupabaseJwtMiddleware` to `Http/Auth/Middleware/`

### Stage 3: HelpScout Consolidation
- [ ] Create `Http/HelpScout/` feature directory
- [ ] Move `HandleHelpScoutExceptionsMiddleware`
- [ ] Move Resources

### Stage 4: HelpScoutController Split
- [ ] Create `DetectRefreshMiddleware`
- [ ] Create `ConversationsController`
- [ ] Create `ProfileController`
- [ ] Update routes to use `Route::match()`

### Stage 5: Documentation
- [ ] Update `app/Presentation/CLAUDE.md`

---

## PR Notes

### Summary
- Jobs reorganized into feature subdirectories (Shopwired/, Mixpanel/, Feeds/, Linnworks/)
- Schedule definitions extracted to per-integration service providers
- Auth components consolidated in Http/Auth/
- HelpScout components consolidated in Http/HelpScout/
- HelpScoutController split with DetectRefreshMiddleware

### Test Plan
- [ ] `make lint` passes
- [ ] `make test` passes
- [ ] `php artisan schedule:list` shows correct jobs
- [ ] HelpScout API endpoints respond correctly
