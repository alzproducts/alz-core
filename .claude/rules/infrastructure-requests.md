---
paths:
  - "app/Infrastructure/**/Requests/*.php"
---

# Infrastructure — Request Class Rules

## Class Shape

- DO declare the class `final readonly` with a **private** constructor; expose a `public static` factory as the only entry point.
- DO name the factory after its input source — `fromCommand(DomainCommand)`, `fromDomain(DomainVO)`, or `fromResolved(...)` when the inputs are already-resolved IDs plus domain value objects. See neighbouring `Requests/*.php` for the conventional name in the integration.
- DO accept domain types (e.g. `Guid`, `Money`, enums, typed IDs) as factory parameters and extract scalars inside. Callers should not re-derive wire values.
- DO return an API-shaped array from `toArray()` using the third party's key names (`StockItemId`, `SupplierID`) — not domain names.
- DO expose `public static buildBulkPayload(...)` for bulk endpoints; the parameter list mirrors the caller's bulk client method.
- DO NOT perform resolution (no SKU→ID lookups, no supplier-name→supplier-ID, no container calls). **Why:** orchestration is a UseCase responsibility — a Request is structural mapping only.
- DO NOT add business logic or conditional behaviour.
- EXCEPTION: pure wire-shape options objects with no domain types to resolve may skip the static factory — constructor alone is fine. Name them `*Options.php`. Canonical: `OrderStatusUpdateOptions`.

Canonical: `AddInventoryItemRequest`, `UpdateStockSupplierStatRequest`.
