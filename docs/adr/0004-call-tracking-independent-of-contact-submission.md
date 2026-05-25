# Call tracking as an independent system, not a ContactSubmission extension

Call-attributed conversions use their own domain model, tables, and submit use case rather than extending or creating `ContactSubmission` records. The two systems share only the downstream platform upload infrastructure (same Google/Bing jobs and DTOs). A `ConversionSubmissionOrchestrator` routes by UUID at submission time — the frontend is source-unaware.

## Considered Options

- **Promote calls to ContactSubmission** — on merge, create a ContactSubmission from call data. Existing pipeline works unchanged but muddies the domain: a phone call is not a form submission.
- **Abstract ConversionSource interface** — polymorphic source ID threaded through submission path. Most flexible but touches every layer for a problem that doesn't yet exist at scale.
- **Independent system with shared upload layer** (chosen) — parallel `SubmitCallLeadConversionUseCase` with ~30-50 lines of orchestration duplication. Maximum independence for future evolution (call completion tracking, caller-to-customer automation, callback workflows).

## Consequences

- Call tracking can evolve without risk to the stable email conversion pipeline.
- Staff sees a unified dashboard (SQL UNION view) despite the backend separation.
- The `ConversionSubmissionOrchestrator` is the only coupling point — a thin try-both-repos router.
- If a third conversion source appears (e.g. chat), it follows the same pattern: own model, own submit use case, orchestrator gains a third lookup.
