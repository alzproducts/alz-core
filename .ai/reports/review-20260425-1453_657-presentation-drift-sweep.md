# Code Review: Issue #657 — Presentation drift sweep

**Date:** 2026-04-25
**Branch:** feature/657-presentation-drift-sweep-domain-vo
**Base:** origin/develop
**Files reviewed:** 15 modified + 6 new = 21 files

## Findings

### CRITICAL
None.

### HIGH

- [ConversationsController.php / ProfileController.php — all routes] Implementation log (`.ai/implementation-logs/657-presentation-drift-sweep.md`) claims a "double-wrap bug" was corrected. Tracing Laravel's `ResourceCollection::jsonSerialize()` shows the OLD code (`new JsonResponse(['data' => Resource::collection(...)])`) and the NEW code (`return Resource::collection(...)`) both produce `{"data": [<items>]}` — `ResourceCollection` only adds the `data` wrap via `toResponse()` (controller return path), not via `jsonSerialize()` (when nested in another array). So the response shape is unchanged. No controller-level integration test exists to confirm this empirically. Status: **Skipped** (user opted to trust static analysis; recommend running a manual smoke test against alz-admin before merge if shape regression is feared).

### MEDIUM

- [`app/Presentation/Http/HelpScout/Resources/AgentProfileResource.php:30-36`] Does not `array_filter` null fields, violating the documented HelpScout convention in `app/Presentation/Http/HelpScout/Resources/CLAUDE.md` ("Null fields: Omit with array_filter() — never include as null"). Sibling resources (`ConversationResource`, `CustomerResource`, `AssigneeResource`, `SnoozeResource`) all filter. The OLD inline `ProfileController` code also did not filter `role`, so this PR preserves the existing wire contract. Status: **Decision: Preserve current behaviour** (defer to follow-up if convention should be unified after frontend Zod schema review).

### LOW

- [`tests/Unit/Domain/Catalog/CustomFields/ValueObjects/DateTimeCustomFieldValueTest.php:143,154`, `NullCustomFieldValueTest.php:53`, `StringCustomFieldValueTest.php:229,251`] Five test methods named `to_array_*` no longer assert on a `toArray()` method (which was removed from Domain). Names became misleading after the refactor. Status: **Fixed** — renamed to reflect what the tests actually exercise (typed accessors).
- [`app/Presentation/Http/Api/Responses/PriceUpdateResponseDTO.php:29-30`] PHPDoc lacked `int<0, max>` constraints used by sibling `BulkUpdateResponseDTO`. Status: **Skipped — would require out-of-scope Application-layer change.** `BulkUpdateResponseDTO` only works because `CostPriceUpdateResult::$total` / `$succeeded` are PHPDoc-tightened to `int<0, max>`. The equivalent source type, `PriceUpdateResult`, uses plain `int`, so adding the constraint to the DTO triggers `argument.type` errors at the `fromResult()` factory. Properly tightening would extend the PR into Application's `PriceUpdateResult`, `withTotal()`, `merge()`, and `fromPhases()` — scope creep. Logged in the implementation plan.
- [`app/Presentation/Http/Api/Responses/PriceUpdateResponseDTO.php:71`] `JsonResponse` did not pass an explicit status (defaulted to 200); sibling DTOs use `Response::HTTP_OK` explicitly. Status: **Fixed** — explicit `Response::HTTP_OK` added.
- [`app/Presentation/Http/Api/Responses/ContactSubmissionAcceptedResponseDTO.php:28`] Class name carries the `Accepted` suffix (HTTP 202 connotation) but returns `Response::HTTP_OK` (200). The OLD inline `ContactFormController` code also returned 200, so the wire contract is preserved. The naming-vs-status mismatch is inherited from the plan. Status: **Skipped** (no contract change; rename or status-bump would be a separate decision).
- [Test coverage] No unit tests for new `AgentProfileResource`, `ContactSubmissionAcceptedResponseDTO`, `PriceUpdateResponseDTO`. Per `/review-code` instruction, missing-test coverage flagged at most LOW. Status: **Deferred** (PR has comprehensive `CustomFieldValueResourceTest`; the three tiny new wrappers are ~30 lines each and largely glue).
- [`app/Presentation/Http/Api/Resources/ProductDetailResource.php:99-103`] Pre-existing inconsistency vs `BrandDetailResource` and `CategoryDetailResource`: does not null-check `$product->customFields` before passing to `CustomFieldValueResource::collection(...)`. Same behaviour as before this PR (the prior `array_map` over a possibly-null array would also have errored). Not introduced by this PR. Status: **Skipped** (pre-existing, out of scope).

## Positive Observations

- **Domain wire-format leak cleanly removed.** `AbstractCustomFieldValue::toArray()` and `DateTimeCustomFieldValue::toArray()` deleted; `CustomFieldValueResource` is the single source of truth for the JSON shape with all wire-format decisions (snake_case keys, ATOM date, enum stringification) co-located in Presentation.
- **Scoped rule prevents future drift.** `.claude/rules/domain-value-objects.md` is correctly scoped to `app/Domain/**/*ValueObject*.php` and `app/Domain/**/ValueObjects/*.php`, with named canonical-violation and canonical-correct examples.
- **Responsable DTOs follow the established pattern.** `PriceUpdateResponseDTO` and `ContactSubmissionAcceptedResponseDTO` match the structure and factory-method convention of `BulkUpdateResponseDTO` / `AsyncRefreshAcceptedResponseDTO`.
- **`CustomFieldValueResourceTest` covers all 6 subtypes** (String, Toggle, DateTime, ValueList, ProductList, Null) with explicit assertions on the wire shape — including the previously-Domain-located ATOM formatting check, now correctly placed at the Presentation boundary.

## Summary

This is a clean architectural cleanup with low blast radius. The Domain → Presentation move is exactly what `app/Domain/CLAUDE.md` mandates, and the new scoped rule codifies the prevention mechanism. The risk surface is the HelpScout endpoint response-shape change (HIGH) — static analysis indicates equivalence, but the implementation log's diagnostic was inaccurate, so a smoke test before merge is prudent. All approved-during-review fixes (test renames, DTO polish) have been applied.
