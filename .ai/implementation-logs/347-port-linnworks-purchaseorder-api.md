# Implementation Log: #347 Port Linnworks PurchaseOrder API

**Branch:** `feature/347-port-linnworks-purchaseorder-api`
**Plan:** `.ai/plans/2026-03-23_347-port-linnworks-purchaseorder-api.md`
**Status:** Complete
**Started:** 2026-03-23

## Decision Log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Build layer by layer: Domain → Application → Infrastructure → Use Cases → Wiring | Follows dependency rule; each layer compiles independently |
| 2 | New `InvalidPurchaseOrderStatusTransitionException` extends `DomainException` | No existing transition exception; business rule violation, not API error |
| 3 | PO Header VO uses primitives (string/float) not Guid for IDs | Matches LinnworksOrder pattern — these are Linnworks-internal IDs |
| 4 | DTOs renamed with DTO suffix, Command moved to UseCases/ | PHPArkitect enforces naming: DTOs/ dir → *DTO suffix; Command is not a DTO |
| 5 | EP diff extracted `resolveCreatesAndUpdates()` private method | PHPStan cognitive complexity limit of 10 |
| 6 | `@throws JsonException` propagated through all layers | ShipMonk checked exceptions rule requires full propagation |
| 7 | `toApiParams()` on DTOs instead of inline serialization | Eliminates duplication between Create/Add use cases |
| 8 | Raw `array<string, mixed>` params in interface (not typed) | Intentional: write-only scope, 17 endpoints — typed params deferred to follow-up |

## Deviations from Plan

- Added `ExtendedPropertyUpdateDTO` (not in plan) — sweep identified untyped array shape in changeset as a rules violation
- Three client methods throw `InvalidApiResponseException` on unexpected response type instead of returning empty arrays — sweep caught silent failure paths

## Progress

### Step 2: Branch Created
- Branch: `feature/347-port-linnworks-purchaseorder-api` from `origin/develop`

### Step 3: Implementation — Complete
- [x] Domain: PurchaseOrderStatus enum, PurchaseOrderReference VO, PurchaseOrderHeader/EP/AdditionalCost/Note VOs, transition exception
- [x] Application: PurchaseOrderClientInterface (17 endpoints), 5 DTOs, ExtendedPropertyDiffService, CreatePurchaseOrderCommand
- [x] Infrastructure: PurchaseOrderClient (3 encoding patterns), 4 response DTOs (Header, EP, AdditionalCost, Note)
- [x] Use cases: Create, AddItems, ChangeStatus, UpdateHeader, UpdateEPs, ModifyAdditionalCosts, AddNote, Delete
- [x] Wiring: LinnworksClientFactory + LinnworksServiceProvider

### Step 4: Existing Tests — Pass (2477)
### Step 5: Lint — Clean (29 PHPStan + 7 PHPArkitect errors fixed)
### Step 6: Tests — 48 new tests (2525 total, 5574 assertions)
### Step 7: Progress Summary — Presented
### Step 8: Simplify — 4 fixes (toApiParams dedup, canTransitionTo inline, O(n) docs)
### Step 9: Sweep — 2 fixes (ExtendedPropertyUpdateDTO, silent failure → exceptions)

## PR Notes

### What
Port all 17 Linnworks PurchaseOrder API endpoints to Clean Architecture with typed DTOs, domain types, and 8 write use cases.

### Why
Replace legacy untyped `AlzPurchase*` service wrappers with a properly layered, type-safe implementation. Read use cases and local persistence deferred to sync strategy.

### Key Decisions
- Single `PurchaseOrderClient` for all 17 endpoints (matches OrderClient/InventoryClient pattern)
- Write use cases only — read paths deferred until sync/persistence strategy decided
- EP diff as dedicated `ExtendedPropertyDiffService` for testability
- Composite `CreatePurchaseOrderUseCase` with delete-and-rethrow cleanup on partial failure
- Domain `PurchaseOrderStatus` enum with transition validation (fail fast before API call)

### Testing
- 48 unit tests covering domain types, EP diff service, and use case branching logic
- All 2525 tests pass, all 5 linters clean
