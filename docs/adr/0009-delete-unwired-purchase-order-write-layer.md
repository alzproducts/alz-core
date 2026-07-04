# ADR 0009: Delete Unwired Purchase Order Write Layer

## Status

Accepted (2026-07-05)

## Context

The entire Linnworks purchase-order **write** layer was ported speculatively in commit `0431ebbb` (2026-03-23, "port PurchaseOrder API to Clean Architecture", [#348](https://github.com/alzproducts/alz-core/issues/348)). No entry point was ever wired up — no controller, route, job, console command, listener, or provider references the 8 write use cases. The live PO surface is read/sync only (sync jobs, backfill commands, dashboards).

Dead code in a public showcase repo is an anti-signal. Recreation from git history + this ADR is cheaper than maintenance.

## Decision

Delete the entire PO write subtree. This ADR preserves the capability knowledge and resurrection pointers.

## What Existed

Full PO write capability:

- **Create PO** — initial header + line items + extended properties, with compensation/rollback on partial failure (delete the PO if item/EP attachment fails)
- **Change status** — transition a PO through Linnworks workflow states
- **Update header** — modify supplier, reference, currency, etc.
- **Extended properties** — add, update, delete via desired-state reconciliation (pure diff → immutable changeset → thin apply)
- **Additional costs** — modify shipping/handling costs
- **Add notes** — append notes to a PO
- **Delete PO** — full deletion

## Linnworks Endpoints Used

All verified working implementations. Linnworks docs are unreliable — the format annotations below are the expensive research.

| Endpoint | Format |
|----------|--------|
| `POST /api/PurchaseOrder/Create_PurchaseOrder_Initial` | form params |
| `POST /api/PurchaseOrder/Add_PurchaseOrderItem` | form params |
| `POST /api/PurchaseOrder/Change_PurchaseOrderStatus` | form params |
| `POST /api/PurchaseOrder/Update_PurchaseOrderHeader` | form params |
| `POST /api/PurchaseOrder/Add_PurchaseOrderExtendedProperty` | JSON wrapper |
| `POST /api/PurchaseOrder/Update_PurchaseOrderExtendedProperty` | JSON wrapper |
| `POST /api/PurchaseOrder/Delete_PurchaseOrderExtendedProperty` | JSON wrapper |
| `POST /api/PurchaseOrder/Modify_AdditionalCost` | JSON wrapper |
| `POST /api/PurchaseOrder/Add_PurchaseOrderNote` | form params |
| `POST /api/PurchaseOrder/Delete_PurchaseOrder` | form params |

## Patterns Worth Resurrecting

### 1. Compensation/Saga — `CreatePurchaseOrderUseCase`

Multi-step creation with rollback: create → items → EPs. On partial failure, deletes the incomplete PO before rethrowing the original exception. Cleanup is wrapped in its own try-catch so it cannot mask the real error.

```
execute(command)
  purchaseId = client.createInitial(command)
  try {
    addLineItems(purchaseId, items)
    addExtendedProperties(purchaseId, command)
  } catch (Throwable e) {
    client.deletePurchaseOrder(purchaseId)  // must-not-throw
    throw e
  }
```

A live sibling of this pattern remains at `GenerateStockItemFromVariationService` (lines 74, 93).

### 2. Desired-State Reconciliation — `ExtendedPropertyDiffService`

Three-class pattern: pure static diff (no I/O, unit-tested) → immutable changeset DTO → thin apply orchestrator.

- `ExtendedPropertyDiffService::diff(current[], desired[])` — indexes both sides by name, resolves creates/updates/deletes
- `ExtendedPropertyChangesetDTO` — immutable value object holding `$toCreate`, `$toUpdate`, `$toDelete`
- `UpdatePurchaseOrderExtendedPropertiesUseCase` — fetches current state, diffs, applies changeset via client

**No live equivalent exists in the codebase.** This is the piece most likely to be wanted again.

## Resurrection Pointers

- **Code last present at:** `a4290b31` (parent of the deleting commit)
- **Original port commit:** `0431ebbb` (2026-03-23)

## Why Deleted

- Unreachable since the March 2026 port — no product surface planned
- Dead code in a public showcase repo is an anti-signal
- Recreation from history + this ADR is cheaper than ongoing maintenance (lint, upgrades, cognitive load)
