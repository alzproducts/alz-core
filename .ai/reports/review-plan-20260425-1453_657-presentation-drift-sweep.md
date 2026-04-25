# Implementation Plan: Issue #657 — Presentation drift sweep (deferred follow-ups)

**Review report:** `.ai/reports/review-20260425-1453_657-presentation-drift-sweep.md`

This plan covers only items NOT resolved during the review session. All approved-during-review fixes have already been applied to the working tree.

## Findings to Address

### 1. Smoke-test HelpScout endpoint response shape [HIGH — verification, not code]
- **Location:** `app/Presentation/Http/Controllers/HelpScout/ConversationsController.php` (4 routes), `app/Presentation/Http/Controllers/HelpScout/ProfileController.php` (1 route)
- **Action:** Before merge, manually verify the alz-admin frontend still parses these endpoints correctly. With the dev server running and `X-Local-Bypass: $API_BYPASS_SECRET`, curl `/helpscout/conversations/assigned`, `/helpscout/conversations/todos`, `/helpscout/conversations/negative-reviews`, `/helpscout/conversations/escalations`, and `/helpscout/profile`. Confirm each returns `{"data": [...]}` (collection) or `{"data": {...}}` (profile) — same shape as before the refactor.
- **Why:** The implementation log's "double-wrap bug" diagnostic was inaccurate; static analysis indicates the response shape is preserved, but no integration test asserts on it, and alz-admin Zod schemas depend on it.

### 2. Decide AgentProfileResource null-filter convention [MEDIUM — separate decision]
- **Location:** `app/Presentation/Http/HelpScout/Resources/AgentProfileResource.php:30-36`
- **Current:** Includes `role: null` when role is unset (preserves prior `ProfileController` inline behaviour).
- **Convention:** `app/Presentation/Http/HelpScout/Resources/CLAUDE.md` says "Null fields: Omit with array_filter()". All other HelpScout resources (`ConversationResource`, `CustomerResource`, `AssigneeResource`, `SnoozeResource`) follow this.
- **Action when revisited:** Coordinate with the alz-admin team on whether the Zod schema for `AgentProfile` accepts `role` as optional (omitted) vs nullable. If optional, wrap return in `array_filter(..., static fn($v): bool => $v !== null)` and update the docblock.
- **Why:** Long-term consistency with the documented HelpScout Resource pattern; one-off divergence becomes a maintenance trap.

### 3. Optional: rename or restatus ContactSubmissionAcceptedResponseDTO [LOW]
- **Location:** `app/Presentation/Http/Api/Responses/ContactSubmissionAcceptedResponseDTO.php:28`
- **Mismatch:** `Accepted` suffix in class name implies HTTP 202; returns `Response::HTTP_OK` (200). Sibling `AsyncRefreshAcceptedResponseDTO` correctly returns 202.
- **Two routes:**
  - **Rename** to `ContactSubmissionResponseDTO` — preserves current 200 wire contract.
  - **Or change status** to `HTTP_ACCEPTED` (202) — matches name but breaks frontend if it asserts on 200.
- **Why:** Naming consistency. Not urgent; the OLD code also returned 200, so no regression vs prior behaviour.

### 4. Optional: tighten `PriceUpdateResult` to `int<0, max>` [LOW]
- **Location:** `app/Application/Shopwired/PricingUpdate/Results/PriceUpdateResult.php:25-26` (and downstream `withTotal()`, `merge()`, `fromPhases()` parameter docblocks).
- **Action:** Change `public int $total` / `public int $succeeded` PHPDoc to `int<0, max>` to mirror `CostPriceUpdateResult`. Then re-add the matching `int<0, max>` constraints to `PriceUpdateResponseDTO::__construct`.
- **Why:** Brings full PHPStan-level parity with the cost-price update path. Counts are semantically non-negative; the source type should communicate that. Decoupled from this PR because it touches Application-layer files outside the issue's stated scope.

### 5. Optional: add unit tests for the three new tiny DTOs / Resource [LOW]
- **Locations:**
  - `app/Presentation/Http/HelpScout/Resources/AgentProfileResource.php`
  - `app/Presentation/Http/Api/Responses/ContactSubmissionAcceptedResponseDTO.php`
  - `app/Presentation/Http/Api/Responses/PriceUpdateResponseDTO.php`
- **Action:** Mirror the `CustomFieldValueResourceTest` pattern — instantiate, call `toArray($request)` / `toResponse($request)`, assert on the resulting structure. ~30 lines each.
- **Why:** Locks the wire shape so accidental drift is caught by CI. Low ROI given the trivial implementations.

## Suggested Order

1. **Item 1 (smoke test)** — must happen before merge if HelpScout shape regression is a real concern.
2. **Item 2 (AgentProfileResource null-filter decision)** — log as separate issue, coordinate with frontend; do not bundle into this PR.
3. **Item 4 (`PriceUpdateResult` tightening)** — quick PHPDoc-only follow-up; pairs naturally with re-adding the DTO constraint.
4. **Item 5 (DTO/Resource tests)** — bundle with items 2 and 4 in the same follow-up PR.
5. **Item 3 (ContactSubmission naming/status)** — lowest priority; defer until next time the contact form contract is touched.
