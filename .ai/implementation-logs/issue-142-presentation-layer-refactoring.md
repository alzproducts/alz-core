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
| Schedule providers use `boot()` with `@throws` | Schedules register at boot time, PHPStan requires checked exception annotations | 2026-01-23 |
| Re-enabled Mixpanel order sync | Issue #134 fixed, schedules now active | 2026-01-23 |
| DetectRefreshMiddleware via request attributes | Controllers read `$request->attributes->get('forceRefresh')` instead of checking HTTP verb - decouples business logic from transport layer | 2026-01-23 |
| ProfileController as invokable | Single-action controller uses `__invoke()` - Laravel convention for focused controllers | 2026-01-23 |

---

## Progress

### ✅ Stage 0: Jobs Directory Refactoring (Pre-work)
- Moved 12 jobs into feature subdirectories
- Updated all namespace imports (commands, providers, routes, tests)
- Moved 5 test files to matching structure

### ✅ Stage 1: Extract Schedule Definitions
- [x] Create `app/Providers/Schedule/` directory
- [x] `ShopwiredScheduleServiceProvider` (6 schedules: orders + customers × 3 tiers)
- [x] `AdsScheduleServiceProvider` (7 schedules: campaign lookup + Google 3 + Bing 3)
- [x] `FeedsScheduleServiceProvider` (1 schedule: DooFinder)
- [x] `LinnworksScheduleServiceProvider` (1 schedule: stock sync)
- [x] `MixpanelScheduleServiceProvider` (2 schedules: order sync nightly + weekly)
- [x] Register in `bootstrap/providers.php`
- [x] Clean up `routes/console.php` (249 → 19 lines)

### ✅ Stage 2: Auth Consolidation
- [x] Move `ValidateSupabaseJwtMiddleware` to `Http/Auth/Middleware/`
- [x] Update namespace imports across codebase
- [x] Move test file to matching structure

### ✅ Stage 3: HelpScout Consolidation
- [x] Create `Http/HelpScout/` feature directory
- [x] Move `HandleHelpScoutExceptionsMiddleware` to `Http/HelpScout/Middleware/`
- [x] Move Resources to `Http/HelpScout/Resources/`
- [x] Move test file to matching structure

### ✅ Stage 4: HelpScoutController Split
- [x] Create `DetectRefreshMiddleware` (POST→forceRefresh:true, GET→forceRefresh:false)
- [x] Create `ConversationsController` (4 methods: assigned, todos, negativeReviews, escalations)
- [x] Create `ProfileController` (single-action invokable controller)
- [x] Update routes to use `Route::match(['get', 'post'], ...)`
- [x] Delete old `HelpScoutController`

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
