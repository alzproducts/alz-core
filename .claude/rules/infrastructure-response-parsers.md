---
paths:
  - "app/Infrastructure/**/*ResponseParserTrait.php"
---

# Infrastructure — Response Parser Trait Rules

## DTO Validation Failures

- DO wrap `{DTO}::from($raw)` calls in a try-catch and translate Spatie DTO parse failures to `InvalidApiResponseException`. See `LinnworksResponseParserTrait::mapDtosFromArray()` for the exact Spatie exception class currently caught (the class name has changed across Spatie versions; that file is the authoritative reference).
- DO log at **CRITICAL** with the error message and `get_debug_type($response)` — do NOT log the raw response (PII and log-size risk).
- DO NOT retry on parse failure — it is a permanent API-contract violation until the DTO is updated.

Canonical: `LinnworksResponseParserTrait`, `ShopwiredResponseParserTrait`.
