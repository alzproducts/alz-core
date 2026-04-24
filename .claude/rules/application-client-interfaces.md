---
paths:
  - "app/Application/Contracts/**/*ClientInterface.php"
---

# Application — Client Interface Rules

## Pre-Resolved Parameters

- DO declare interface parameters as **pre-resolved** domain values: `Guid $supplierGuid`, `array<string, Money> $prices`.
- DO NOT accept raw names or identifiers that require resolution inside the client: `string $supplierName` (requires supplierName→GUID lookup the client shouldn't own).
- The UseCase orchestrates all resolution (SKU→stockItemId, supplierName→GUID) via dedicated Resolver classes before calling the client. **Why:** Resolution is orchestration — it involves business decisions (batch vs single, caching, error handling). Infrastructure clients are structural mappers, not orchestrators.
