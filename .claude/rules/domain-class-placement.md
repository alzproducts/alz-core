---
paths:
  - "app/Domain/**/*.php"
---

# Domain — New Class Placement Gate

Before adding a new class under `app/Domain/`, answer three questions:

- DOES the class enforce an invariant? (`Assert::*`, validated property hooks, refuse-impossible-state guards.)
- DOES it carry business logic — decisions, rules, derivations?
- DO callers reason about it in domain terms, or could they treat it as opaque data?

If the answer to all three is "no", the class does NOT belong in Domain.

**EXCEPTION:** if another class under `app/Domain/` holds this one as a property type, constructor parameter type, or `list<X>` element, it stays in Domain. **Why:** moving it would force the Domain parent to import Application, violating the dependency rule. Canonical: `StockStatus` (child of `ProductView`), `PurchaseOrderItem` (child of `PurchaseOrderCore`).

Otherwise, redirect:

- Classification labels / behaviour-free enums → `App\Application\{Feature}\Enums`. Canonical: `AdPlatform` (Google/Bing string-backed enum, no invariants, no methods).
- Layer-boundary data shapes → `App\Application\{Feature}\DTOs`.
- Write-operation parameter objects → `App\Application\{Feature}\Commands`. Canonical: `LeadConversionCommand`.
