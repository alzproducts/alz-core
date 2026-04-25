---
paths:
  - "app/Infrastructure/**/*Transport.php"
  - "!app/Infrastructure/**/Logging*Transport.php"
---

# Infrastructure — HTTP Transport Rules

## Exception Translation

- DO wrap every HTTP/SDK call in try-catch and translate to a Domain API exception — nothing escapes the transport untranslated.
- DO log technical details (status code, error message) BEFORE translating — this context does not survive the Domain exception.
- DO reuse the existing translation matrix. See the catch-blocks in `LinnworksHttpTransport` or `ShopwiredHttpTransport` for the current set of status → Domain-API-exception mappings. DO NOT invent new Domain API exception classes when the codebase already has one for the condition.
- DO NOT catch just to log and rethrow the raw SDK exception — translate, or don't catch.
- DO NOT return empty arrays or null to hide failures — throw.

Canonical: `LinnworksHttpTransport`, `ShopwiredHttpTransport`.
