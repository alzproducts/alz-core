---
paths:
  - "app/Presentation/Http/**/*Controller.php"
---

# Controller Rules

## Exception Handling

- DO NOT add try-catch — `InternalApiExceptionMapper` (registered in `bootstrap/app.php`) maps all domain exceptions to JSON responses globally; per-controller catches duplicate this logic
- EXCEPTION: catch only when you need a transaction rollback + redirect in the same action — the global `render()` handler runs after the response has already been sent

## Class Shape

- DO declare `final readonly` — controllers are leaf nodes, not reusable bases
- DO write single-action invokables (`__invoke`) for new controllers
- DO use multi-action controllers only when all actions share identical constructor dependencies and belong to the same route group

## Request Parsing

- DO validate and type request data with Spatie Data: `SomeDTO::from($request)` at the top of the action
- DO NOT use FormRequests — Spatie Data performs validation and type-narrowing in one step
