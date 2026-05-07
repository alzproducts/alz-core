# Sentry ALZ-CORE-AR — Custom Fields & Filter Groups Self-Heal

Status: design complete — ready for implementation
Linear: COR-123
Plan: .ai/plans/2026-05-06_COR-123-self-heal-custom-field-staleness-and-filter-group-sync.md
Sentry: ALZ-CORE-AR (alzproducts-mx) — `InvalidCustomFieldValueException` on `GET /api/categories/{id}/custom-fields`

## Problem
Recurring 500s when ShopWired admin field-type changes but local definitions haven't synced yet. Throw site: `CustomFieldValueFactory.php:123`. User says it's frequent, normally fixed by manually dispatching `SyncShopwiredCustomFieldsJob`. Filter groups have a silent variant of the same drift (no exception — `FilterGroupRegistry::findByOptionNo` returns null).

## Decisions (locked)

### Layer 1 — Self-heal on exception (custom fields)
- **Trigger location:** tiny Application-layer wrapper service (NOT bootstrap/app.php — that file in this codebase is observability/delivery only; adding domain reactions breaks its established role). Used by `Get{Brand,Category,Product}CustomFieldsUseCase` (3 sites).
- **Wrapper shape:** callable wrapper (decorator-style) — `withRecovery(callable $work): mixed` with `@template T` for type safety. Co-locates catch/log/dispatch/rethrow so callers can't forget to rethrow. Concrete `final readonly class CustomFieldStalenessRecovery` in `Application/Catalog/Services/`. No interface (single implementation, same layer).
- **Cooldown:** dropped. Rely on `SyncShopwiredCustomFieldsJob`'s built-in `ShouldBeUnique uniqueFor=120` for queue dedupe. No cache-lock orphan-state failure mode.
- **Signal scope:** only `InvalidCustomFieldValueException` (the 500-causing path). NOT triggered on `UnknownCustomFieldReporter` signals (those are graceful-degradation logs only).
- **Read-path tolerance:** declined. First request after drift still 500s.
- **Dispatcher method:** add `dispatchCustomFieldsSync()` to `ShopwiredSyncDispatcherInterface` + `QueuedShopwiredSyncDispatcher`.

### Layer 2 — Proactive sync (custom fields + filter groups)
- **Shape:** filter-groups schedule + deploy-time dispatch for both.
- **Filter groups today:** `SyncShopwiredFilterGroupsJob` exists but has NO production schedule (`ShopwiredScheduleServiceProvider::boot()` doesn't register one). Manual dispatch only. Drift is silent.
- **Filter groups schedule cadence:** hourly (matching custom fields — simpler mental model, all ShopWired definition syncs same cadence).
- **Custom fields today:** already hourly via `registerCustomFieldSchedule()`. Job class docstring is stale (says "weekly").
- **Deploy-time hook:** new artisan command `app:dispatch-baseline-syncs` (`DispatchBaselineSyncsCommand`), gated behind `DISPATCH_BASELINE_SYNCS=true` env var (set on web service only in Railway). Non-fatal: failure logs warning, does NOT crash entrypoint. Dispatches both `SyncShopwiredCustomFieldsJob` + `SyncShopwiredFilterGroupsJob`. `ShouldBeUnique uniqueFor=120` deduplicates if both web and worker fire.

## Pending decisions

All resolved — see locked decisions above. Remaining minor: fix stale docstring on `SyncShopwiredCustomFieldsJob` ("Weekly" → "Hourly").

## Files in scope (to edit)

- `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php` — add `dispatchCustomFieldsSync()` (and possibly `dispatchFilterGroupsSync()`)
- `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php` — implement
- `app/Application/Catalog/Services/CustomFieldStalenessRecovery.php` — new callable wrapper
- `app/Application/Catalog/UseCases/GetBrandCustomFieldsUseCase.php` — wire wrapper
- `app/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php` — wire wrapper
- `app/Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php` — wire wrapper
- `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php` — add `registerFilterGroupSchedule()` + boot() call
- `app/Infrastructure/Jobs/Shopwired/SyncShopwiredCustomFieldsJob.php` — fix stale docstring
- `docker-entrypoint.sh` — deploy hook
- `app/Presentation/Console/Commands/DispatchBaselineSyncsCommand.php` — deploy-time dispatch command

## Verifying assumptions before code change

- Confirm three use cases (`Get{Brand,Category,Product}CustomFieldsUseCase`) all flow through `CustomFieldFactory::fromRawFields` — verified for `CategoryViewAssembler.php:107`, others by analogy. Re-verify before editing.
- Confirm `Schedule::job()` dedupe semantics (`onOneServer` + `withoutOverlapping`) won't conflict with `ShouldBeUnique`.
- Confirm Railway `alz-core-web` vs `alz-core-worker` Docker CMD overrides — entrypoint runs in both, so deploy-hook gating must be explicit env var.

## Out of scope

- Read-path tolerance (user declined — first 500 is acceptable).
- Dispatching on `UnknownCustomFieldReporter` signal (different failure mode, no user-visible impact).
- ShopWired webhooks for definition changes (would be ideal but not in this PR).
