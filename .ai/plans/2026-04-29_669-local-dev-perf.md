# Plan: Local Dev Performance Fixes

**Issue**: #669  
**Date**: 2026-04-29  
**Branch**: `feature/669-local-dev-perf`  
**Scope**: #1 warning-flood fix + db-reset-full upgrade; #2 Octane watch glob tightening

---

## Background

A diagnostic session against alz-core/alz-admin logs identified two compounding root causes making the local dev server practically unusable:

1. **Warning flood**: `CustomFieldFactory::fromRawFields()` calls `Log::warning('Unknown custom field...')` once per unrecognised field per product. With 537 products and `related_products` missing from the local `shopwired.custom_field_definitions` table after `make db-reset-full`, every `/api/products` request writes ~537 log entries synchronously.

2. **Octane worker thrash**: `config/octane.php` watch list includes `'app'` as a bare directory with no extension filter. Every file event inside `app/` (IDE temp files, `.swp`, `.DS_Store`, PhpStorm safe-write siblings) triggers `Application change detected. Restarting workers...`. Observed 5,154 restarts in one day's log — workers are never warm.

**Confirmed not a separate issue**: `CachingHelpScoutService` already implements 5-minute caching via `GracefulCache::remember()`. The observed 2–6s HelpScout response times are emergent from #1 + #2 (cold TLS connections on restart, I/O pressure). Verify post-merge; open a separate issue only if still slow.

**Deferred (not in this PR)**: `/api/products` N+1 investigation (#4), Supabase middleware fetch failures (#5).

---

## Changes

### Change 1 — `app/Infrastructure/Shopwired/Factories/CustomFieldFactory.php`

**Dedupe the warning** (per-request summary instead of per-occurrence):

- Add instance-level mutable property `private array $warnedFieldCounts = []`. Keep `readonly` on injected constructor params (PHP 8.4 per-property modifiers).
- In the unknown-field branch (currently line 68): replace `Log::warning(...)` with `$this->warnedFieldCounts[$name] = ($this->warnedFieldCounts[$name] ?? 0) + 1;`.
- In `__construct`, register `app()->terminating(function (): void { ... })`. The closure reads `$this->warnedFieldCounts`; if non-empty, emits one `Log::warning('Unknown custom fields encountered - run dev:seed-sync', ['fields' => $this->warnedFieldCounts, 'item_type' => $this->itemType->value])`.

**Fix stale warning text**:
- Line 47 docblock: `SyncCustomFieldsJob` → `SyncShopwiredCustomFieldsJob`
- Lines 68/106: `app:sync-custom-fields` → `dev:seed-sync` (the `app:sync-custom-fields` command does not exist; the real command is `dev:seed-sync`)

### Change 2 — `config/octane.php`

Tighten watch globs so only PHP files trigger worker restarts:

```php
// Before:
'app',
'routes',

// After:
'app/**/*.php',
'routes/**/*.php',
```

### Change 3 — `Makefile` (db-reset-full upgrade)

`make db-reset-full` currently runs only Supabase reset + `migrate`. After every reset, `shopwired.custom_field_definitions` is empty, causing the warning flood on first request.

Fix: replace the `$(MAKE) migrate` step with `$(EXEC) artisan dev:seed-sync`, which internally runs `migrate` then dispatches 9 core sync jobs including `SyncShopwiredCustomFieldsJob`.

```makefile
db-reset-full: ## Full database reset (Supabase auth + Laravel migrations + core data sync)
    @echo "$(YELLOW)=== FULL DATABASE RESET ===$(NC)"
    @echo "$(YELLOW)Step 1/2: Resetting Supabase (auth tables, test users)...$(NC)"
    @$(MAKE) supabase-reset
    @echo ""
    @echo "$(YELLOW)Step 2/2: Migrating + dispatching core sync jobs...$(NC)"
    @$(EXEC) artisan dev:seed-sync
    @echo ""
    @echo "$(GREEN)Full database reset complete.$(NC)"
    @echo "$(GREEN)- Supabase auth tables: reset$(NC)"
    @echo "$(GREEN)- Laravel tables: migrated$(NC)"
    @echo "$(GREEN)- Core sync jobs: dispatched (queue worker must be running)$(NC)"
    @echo "$(YELLOW)Note: DB populates async — wait for queue to drain before testing.$(NC)"
```

Add a companion target:

```makefile
db-reset-full-pii: ## Full reset including PII sync (customers + orders)
    @$(MAKE) supabase-reset
    @$(EXEC) artisan dev:seed-sync --incl-pii
```

---

## Key Decisions (from grill-me session)

| Decision | Chosen | Rationale |
|----------|--------|-----------|
| Warning fix strategy | Both root + dedupe | Root fix alone is fragile on every db-reset-full; dedupe protects prod from future drift |
| Log dedupe shape | Per-request summary via `app()->terminating()` | Zero latency impact (fires after response sent); self-contained in factory |
| Flush mechanism | `app()->terminating(...)` from constructor | No extra class/middleware; safe under Octane scoped binding lifecycle |
| Octane watch fix | Tighten globs | Keeps hot-reload workflow; log files were NOT the cause (not in watch list) |
| db-reset-full | Replace migrate with dev:seed-sync | Makes fix permanent; dev:seed-sync runs migrate internally |
| HelpScout caching | Descoped | CachingHelpScoutService already exists and caches; observed slowness is downstream of #1+#2 |
| Issue shape | One umbrella PR | Two tractable fixes, no split warranted |

---

## Verification (manual, no code)

After merge and a fresh dev session:

1. Tail `storage/logs/octane.log` while editing files — `Application change detected` should fire only on `.php` file saves, not on IDE housekeeping writes.
2. Run `make db-reset-full`, ensure queue worker is running (`make redis` if needed, then the Queue run config), then reload `/api/products` — expect zero bare `Unknown custom field` warnings; at most one summary warning per request during the queue-drain window.
3. Reload the HelpScout dashboard — expect sub-second response (was 2–6s before).

---

## Risks

- During queue-drain window after `make db-reset-full`, the warning dedupe still fires (one summary per request). Considered acceptable — degraded-but-usable vs. unusable before.
- `app()->terminating(...)` callbacks fire after response is sent, so no request latency impact.
- No automated test added (per user decision — perf delta will be obvious on manual test).
