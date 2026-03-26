# Implementation Plan: Review Findings for Issue #363

**Review report:** `.ai/reports/review-20260325-1430_363-get-product-show.md`
**Triage date:** 2026-03-25

## Triage Results

| Finding | Severity | Decision |
|---------|----------|----------|
| M1: cost_price always in base response | Medium | **Skip** — intentional design |
| M2: Full cost price map for single product | Medium | **Skip** — YAGNI |
| L1: No show endpoint feature tests | Low | **Skip** — planned for later commit |
| L2+L3: Spec deviation + double-parse | Low | **Fix** — update issue spec |

## Accepted Findings

### 1. Update GitHub issue spec to reflect actual API contract

- **Severity:** Low
- **File:** GitHub issue #363
- **Proposed fix:** Update the Success Criteria section to reflect:
  - `cost_price` is always included in base response (not a conditional embed)
  - `sale_settings` added as a conditional embed
  - Actual include list: `variations`, `description`, `category_ids`, `custom_fields`, `filters`, `sale_settings`
- **Why it matters:** Keeps the issue as accurate documentation of what was built. Prevents confusion if someone reads the issue later and expects `cost_price` to be conditional.

## Suggested Implementation Order

1. Update GitHub issue #363 description (documentation only, no code changes)
