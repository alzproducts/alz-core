# Implementation Log: #645 — Complete Presentation Layer Scoped Rules Migration

## Issue Context

PR #644 migrated only two of five planned Presentation layer rule files (controllers and commands) and skipped the FormRequest migration entirely. This issue completes the full migration:
- Five path-scoped rule files covering every Presentation file type
- Replacing the only legacy `FormRequest` in the codebase with a Spatie Data DTO pair
- Trimming `app/Presentation/CLAUDE.md` to architecture-only content

## Implementation

### Rule Files

- `.claude/rules/presentation-controllers.md` — replaced with full planned rule set (splitting, `@throws`, VO-at-boundary, `BuildsPaginatedResponseTrait`, invokable, canonical pointers)
- `.claude/rules/presentation-request-dtos.md` — created, scoped to `*RequestDTO.php`
- `.claude/rules/presentation-api-resources.md` — created, scoped to `Http/Api/Resources/` only
- `.claude/rules/presentation-http-middleware.md` — created
- `.claude/rules/presentation-console-commands.md` — created (did not exist previously; `presentation-commands.md` was referenced in old CLAUDE.md pointer but never created)

### FormRequest Migration

- `app/Presentation/Http/Api/DTOs/UpdateFreeDeliveryRequestDTO.php` — created; mirrors `UpdateCostPricesRequestDTO` shape
- `app/Presentation/Http/Api/DTOs/FreeDeliveryUpdateItemDTO.php` — created; holds wire types, constructs `SetFreeDeliveryCommand` in `toCommand()`; uses `Rule::enum(FreeDeliveryType::class)` matching the original `Rule::in(...)` semantics
- `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — swapped `SetFreeDeliveryRequest $request` → `UpdateFreeDeliveryRequestDTO $data`; command building now uses `iterator_to_array()` + `->toCommand()` matching `updateCostPrices` pattern; removed stale `@throws ValueError`, `FreeDeliveryType`, and `SetFreeDeliveryRequest` imports
- `app/Presentation/Http/Requests/SetFreeDeliveryRequest.php` — deleted
- `app/Presentation/Http/Requests/` — deleted (empty after removal)

### CLAUDE.md Trim

- `app/Presentation/CLAUDE.md` — trimmed: removed Directory Organization table (discoverable from tree), removed Naming bullet (now in controller rule), added Exception Handling decision tree, added FormRequest ban anti-pattern, added Golden Rule, updated rule pointer

## Test Results

3222 passed (7344 assertions) — 12 pre-existing notices in `GracefulCacheTest` (mock expectations), unrelated to this change.

## Lint Results

All five linters passed after one fix iteration:
- **PHPStan**: Initial failure — `@throws ValueError` missing from `updateFreeDelivery()` because `FreeDeliveryUpdateItemDTO::toCommand()` propagates it. Added `@throws ValueError` annotation + `ValueError` import back to controller. Passed on second run.
- **Pint, PHPArkitect, Deptrac, TLint**: Passed on first run.

## Handoff Notes

- `presentation-console-commands.md` was created new (the old CLAUDE.md pointer referenced `presentation-commands.md` which never existed)
- `Rule::enum(FreeDeliveryType::class)` matches original `Rule::in(...)` semantics exactly — both accept 'none', 'Standard', 'Express' (the backed enum case values)
- `ValueError` is retained in controller `@throws` even though Spatie validation prevents it at runtime — ShipMonk's static analysis requires it because `toCommand()` is declared as throwing it
- The `Http/Requests/` directory is fully deleted; deptrac cache will auto-regenerate on next run
