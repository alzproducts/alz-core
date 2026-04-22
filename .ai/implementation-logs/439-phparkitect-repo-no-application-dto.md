# Implementation Log: #439 — PHPArkitect - repositories must not depend on Application DTOs

## Issue Context
Rule 3 in `phparkitect.php` allowed Infrastructure to depend on the entire `App\Application` namespace — a gap that let repositories accept/return `App\Application\*\DTOs\*` classes, coupling persistence to presentation-facing formats.

Issue asked for:
- New targeted PHPArkitect rule blocking `Infrastructure\*\Repositories\*` → `Application\*\DTOs\*`
- `make lint` passes, with any legitimate existing violations fixed

## Implementation

### 1. New PHPArkitect rule (phparkitect.php)
- Imported `NotDependsOnTheseNamespaces` (a blocklist expression; the installed PHPArkitect 0.8 ships it — no custom `Expression` implementation needed, contrary to the issue's implementation note).
- Added **Rule 4b** after Rule 4 (Presentation) targeting `App\Infrastructure\*\Repositories` with forbidden namespaces:
  - `App\Application\DTOs\*`
  - `App\Application\*\DTOs\*`
- **Pattern-syntax gotcha**: PHPArkitect uses two different matching modes. Without wildcards it's prefix-matched with auto `\` terminator (`App\Application\DTOs` catches all descendants). With wildcards it uses `fnmatch`, which is strict — `App\Application\*\DTOs` only matches the namespace itself, NOT classes inside. Classes need explicit trailing `*`. Both forms (root DTOs, nested DTOs) are covered by the two patterns above.
- Rule includes full violation/correct examples and a `because` clause.

### 2. Refactor of 4 existing violators

After discussion (pre-existing architecture called for alignment, not just rule addition), we grouped all three reconciliation-produced change objects under `Application\Catalog\Commands` and moved the generic paginated list to `Domain\ValueObjects`:

| Before | After |
|---|---|
| `App\Application\DTOs\PaginatedListDTO` | `App\Domain\ValueObjects\PaginatedList` |
| `App\Application\Catalog\DTOs\ProductSortOrderChangeDTO` | `App\Application\Catalog\Commands\ProductSortOrderChangeCommand` |
| `App\Application\Catalog\DTOs\ProductFilterChangeDTO` | `App\Application\Catalog\Commands\ProductFilterChangeCommand` |
| `App\Application\ReviewsIo\DTOs\ProductRatingChangeDTO` | `App\Application\Catalog\Commands\ProductRatingChangeCommand` |

**Classification rationale**:
- `PaginatedList` — generic cross-cutting pagination wrapper with zero framework deps. Already "framework-free" per its own docblock. Domain-level primitive alongside `IntId`, `Guid`.
- The three `*ChangeCommand` VOs — all are **reconciliation-produced commands**: a read-side Postgres view detects drift and emits immutable *"apply these changes"* intents. The UseCase unpacks them into primitives and dispatches async jobs.  These are Commands in the CQRS sense (intent to change state, immutable data) even though their origin is a query result rather than an API request. `ProductRatingChange` went to `Catalog\Commands` despite being sourced from ReviewsIo — the subject is a product rating; the source integration is incidental.
- Moved all three to Application layer (not Domain) because `ProductFilterChange` carries `optionNo`, a ShopWired-specific transport concept. Grouping all three under `Application\Catalog\Commands\` keeps the categorisation consistent.
- `Command` suffix chosen over `Change` to satisfy the existing Application-layer naming rule (Rule 7 in `phparkitect.php`, line 388-396 — Application classes must match `*UseCase|*Service|*Transformer|*DTO|*Command|…`).

### 3. Files touched

**Moved (with `git mv`, history preserved)**:
- `PaginatedListDTO.php` → `app/Domain/ValueObjects/PaginatedList.php`
- `ProductSortOrderChangeDTO.php` → `app/Application/Catalog/Commands/ProductSortOrderChangeCommand.php`
- `ProductFilterChangeDTO.php` → `app/Application/Catalog/Commands/ProductFilterChangeCommand.php`
- `ProductRatingChangeDTO.php` → `app/Application/Catalog/Commands/ProductRatingChangeCommand.php`
- `PaginatedListDTOTest.php` → `tests/Unit/Domain/ValueObjects/PaginatedListTest.php`
- `ProductFilterChangeDTOTest.php` → `tests/Unit/Application/Catalog/Commands/ProductFilterChangeCommandTest.php`

**Imports + class-name updates (~50 files)**: 11 Infrastructure repositories, 9 Application interfaces, 10 Application use cases, 1 Infrastructure dispatcher/client, 1 Presentation trait, 1 Infrastructure gateway, ~15 test files.

**Deleted (empty after moves)**:
- `app/Application/DTOs/`
- `app/Application/Catalog/DTOs/`
- `app/Application/ReviewsIo/DTOs/`
- `tests/Unit/Application/DTOs/`
- `tests/Unit/Application/Catalog/DTOs/`

## Test Results

- **Full suite**: `make test` — **3160 passed** (7194 assertions), 15.6s
- No test logic changed — only imports and type references

## Lint Results

All five linters pass:
- **Pint** — pass (auto-fixed `ordered_imports`, `braces_position`, `single_line_empty_body` after initial edits)
- **PHPStan (level max)** — no errors
- **PHPArkitect** — ✅ no violations (including the new Rule 4b)
- **Deptrac** — 0 violations, 11938 allowed
- **TLint** — LGTM

**Bug fixed during development**: first Rule 4b attempt used `'App\Application\*\DTOs'` as the forbidden pattern but only caught 4 of 11 violations — `fnmatch` requires the trailing `*` to match classes inside a namespace. Fixed to `'App\Application\*\DTOs\*'` + `'App\Application\DTOs\*'`, which then correctly flagged all 11.

## Handoff Notes

### What changed and why

1. **New PHPArkitect Rule 4b**: blocks repositories from importing Application DTOs. Uses `NotDependsOnTheseNamespaces` with two patterns to cover both root and nested `DTOs/` directories.
2. **All 4 legacy violators refactored** (not excluded) — the rule now passes with zero suppressions.
3. **Design clarification codified**: introduced `Application\Catalog\Commands\` as the shelf for reconciliation-produced state-change commands. Three such commands now live there; new sibling commands in future will follow the same pattern.
4. **`PaginatedList`** elevated to Domain as a generic VO — sibling to `IntId`, `Guid`, `DateRange`. This aligns with its own docblock ("framework-free") and lets it be used from any layer without triggering the new rule.

### Areas worth a second look in review

- The `Catalog` namespace for `ProductRatingChangeCommand` (sourced from ReviewsIo). The alternative placement is `App\Application\ReviewsIo\Commands\`. Chose `Catalog` because: (a) the subject being changed is a product rating; (b) the consuming UseCase (`UpdateShopwiredRatingsUseCase`) pushes to ShopWired/Catalog; (c) no existing `Application\ReviewsIo\Commands\` dir.
- **No existing test logic changed** — only SUT class names and imports. Coverage unchanged.
- The three `*Command` classes don't implement a common interface. Each is a plain `final readonly class`. That matches the existing pattern (no Command handler interface exists). If a future PR introduces CQRS infrastructure, these will need tagging.
- Renamed `mapRowsToDtos` → `mapRowsToCommands` in all 6 query repositories (`RatingFilterQueryRepository`, `OffersFilterQueryRepository`, `ShippingOffersFilterQueryRepository`, `ShippingOptionsFilterQueryRepository`, `VatReliefFilterQueryRepository`, `ProductSortOrderQueryRepository`) so the private helper's name matches what it produces.

### PR Notes (draft)

**Title**: `feat(arch): prevent repositories from depending on Application DTOs`

**Summary**:
- Adds PHPArkitect Rule 4b blocking `Infrastructure\*\Repositories\*` → `Application\*\DTOs\*`
- Refactors 4 legacy violators — moves `PaginatedListDTO` to Domain, renames 3 reconciliation result DTOs to `*ChangeCommand` classes under `Application\Catalog\Commands\`
- Rule passes with zero exclusions or suppressions
- 3160 tests pass; 5 linters green

**Test plan**:
- [x] `make test` — 3160 passed
- [x] `make lint` — Pint, PHPStan, PHPArkitect, Deptrac, TLint all green
- [x] New Rule 4b detects future violations (verified by adding and observing the pattern catch 4 cases, then all 11)
