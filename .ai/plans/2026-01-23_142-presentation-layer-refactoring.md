# Presentation Layer Refactoring Plan

## Overview
Reorganize the Presentation layer for better scalability and consistency, applying feature-based organization across all concerns.

**Execution**: Single PR — namespace changes are interconnected, easier to review holistically.

---

## Organizational Principle
**Feature boundaries should be consistent across all Presentation concerns.** Create subdirectories when a feature has **2+ related files**.

---

## 1. Extract Schedule Definitions to Service Providers
**Problem**: `routes/console.php` is 249 lines and growing with each integration.

**Approach**:
- Create `app/Providers/Schedule/` directory
- Extract per-integration schedule providers:
  - `ShopwiredScheduleServiceProvider`
  - `AdsScheduleServiceProvider` (Google + Bing)
  - `FeedsScheduleServiceProvider`
  - `LinnworksScheduleServiceProvider`
  - `MixpanelScheduleServiceProvider`
- Register in `bootstrap/providers.php`
- `routes/console.php` becomes minimal (imports only)

---

## 2. Split HelpScoutController + Refresh Middleware
**Problem**: 208 lines, 10 methods, mixed concerns, refresh/non-refresh duplication (8 methods where 4 suffice).

**Approach**:
- Create `Http/Controllers/HelpScout/` directory
- Split into:
  - `ConversationsController` (assigned, todos, negativeReviews, escalations — 4 methods instead of 8)
  - `ProfileController` (profile endpoint)
- Create `DetectRefreshMiddleware` in `Http/HelpScout/Middleware/`:
  - POST request → sets `forceRefresh: true` on request attributes
  - GET request → sets `forceRefresh: false`
- Update routes to use `Route::match(['get', 'post'], ...)` with middleware
- Controller reads `$request->attributes->get('forceRefresh')` — no longer cares about HTTP verb

**Result**: 4 controller methods, 4 routes (down from 8 each)

---

## 3. Consolidate Auth Components
**Problem**: Auth code fragmented across 3 locations.

**Approach**:
- Move `ValidateSupabaseJwtMiddleware` from `Http/Middleware/` to `Http/Auth/Middleware/`
- Result: All auth concerns in `Http/Auth/`:
  ```
  Http/Auth/
  ├── Exceptions/
  │   └── InvalidJwtClaimsException.php
  ├── Middleware/
  │   └── ValidateSupabaseJwtMiddleware.php
  └── SupabaseJwtParser.php
  ```

---

## 4. Consolidate HelpScout HTTP Components
**Problem**: HelpScout middleware and resources separated from controllers.

**Approach**:
- Create `Http/HelpScout/` feature directory
- Move `HandleHelpScoutExceptionsMiddleware` to `Http/HelpScout/Middleware/`
- Move `Http/Resources/HelpScout/` to `Http/HelpScout/Resources/`
- Result:
  ```
  Http/HelpScout/
  ├── Middleware/
  │   └── HandleHelpScoutExceptionsMiddleware.php
  └── Resources/
      ├── ConversationResource.php
      └── ...
  ```

---

## 5. Global Middleware Directory (Cleanup)
**Problem**: `Http/Middleware/` mixes global and feature-specific.

**Approach**:
- After moving feature-specific middleware, `Http/Middleware/` contains only global:
  - `EnsureUserApprovedMiddleware`
  - `HorizonBasicAuthMiddleware`
  - `SetRlsContextMiddleware`

---

## 6. Documentation Updates
**Problem**: No documented conventions for naming or organization.

**Approach**:
- Add to `app/Presentation/CLAUDE.md`:
  - Job naming convention: `Sync*` = data sync, `Process*` = transform/generate, `Reconcile*` = compare/fix
  - Feature directory threshold rule
  - FormRequest pattern (when first one is created)

---

## Execution Order
1. Schedule providers (biggest scalability win, isolated change)
2. Auth consolidation (small, low risk)
3. HelpScout consolidation (middleware + resources)
4. HelpScoutController split + DetectRefreshMiddleware (depends on #3 for clean structure)
5. Documentation updates (capture decisions)

---

## Verification
- `make lint` passes after each step
- `make test` passes after each step
- Routes still resolve correctly (test API endpoints)
- Scheduled jobs run correctly (`php artisan schedule:list`)
