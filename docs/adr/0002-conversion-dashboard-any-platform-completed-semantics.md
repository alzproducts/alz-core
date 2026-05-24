# ADR-0002: Dashboard `lead_status` aggregates as "any platform completed = completed"

**Context.** Adding Bing Ads offline conversion tracking means a single contact submission can have multiple `lead_received` action rows in `contact_submission_actions` — one per ad platform. The `marketing.contact_submission_dashboard_view` previously LEFT JOINed to a single `lead_received` row; with per-platform rows, the view must aggregate them into one `lead_status` column to avoid row duplication.

**Decision.** The LATERAL subquery uses `completed if ANY platform completed` semantics: if at least one platform's upload succeeded, `lead_status = 'completed'`. Only if all platforms failed does it show `failed`. The dashboard's existing Failed tab catches per-platform issues for admin attention.

**Why.** The alternative — "completed only if ALL platforms completed" — blocks the staff workflow. If a Google upload succeeds but Bing fails (transient API issue, misconfigured goal name), the submission would stay out of "Awaiting Quote" until Bing is fixed. That punishes staff for an infrastructure problem. The business question is "did we successfully report this lead to an ad platform for bid optimisation?" — one success answers that. Per-platform failure visibility is already handled by the Failed tab.

**Considered Options.**
- **All completed = completed**: Strict, but blocks happy-path tabs on single-platform failures.
- **Worst-case status (failed > processing > pending > completed)**: Most conservative, but floods the Failed tab with submissions that are partially successful.
- **Per-platform columns (`lead_status_google`, `lead_status_bing`)**: Full visibility, but couples the view schema to the platform list — every new platform requires a view migration and frontend update.
