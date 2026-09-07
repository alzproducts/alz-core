# README Review Report

**Date:** 2026-09-07
**Purpose:** Findings from a structured review of `README.md` (247 lines at commit `b77e2181`) ahead of a rewrite. Audience for the README is an unknown mid-to-senior engineer reading the repo as part of an interview process. Review dimensions: messaging and positioning, structure and length, writing quality. Factual claims were verified where the rewrite will restate them.

---

## Table of Contents

1. [Method](#1-method)
2. [Verification Results](#2-verification-results)
3. [Findings: Structure](#3-findings-structure)
4. [Findings: Messaging and Positioning](#4-findings-messaging-and-positioning)
5. [Findings: Writing Quality and Rendering](#5-findings-writing-quality-and-rendering)
6. [Withdrawn Findings](#6-withdrawn-findings)
7. [Decisions](#7-decisions)
8. [Suggested Section Order](#8-suggested-section-order)
9. [Changes Since the Last Rewrite](#9-changes-since-the-last-rewrite)

---

## 1. Method

- Read `README.md` in full and `docs/architecture-overview.md` headings and layer section for overlap.
- Rendered the README on GitHub in dark theme and inspected badges, both Mermaid diagrams, tables, and the repository landing page.
- Dispatched three read-only verifier passes against the codebase: caching claims, CI and testing claims, headline numbers. Each result below carries a repo-relative evidence pointer and, for counts, a reproducible command.
- Findings checkable from the README text alone were confirmed directly and are marked **[README]**. Findings confirmed by a verifier are marked **[VERIFIED]**. Findings confirmed by rendering are marked **[RENDER]**.

## 2. Verification Results

### 2.1 Caching claims (README lines 70-80, 109)

| Claim | Verdict | Evidence |
|-------|---------|----------|
| "No caching layer" | CONTRADICTED as written | `app/Providers/CacheServiceProvider.php:47-107` registers `ResilientCacheInterface`, `LockableCacheInterface`, `LockManagerInterface`, `TransientLogThrottle` over Laravel's `CacheManager` |
| Redis used only for queues (Tech Stack row) | CONTRADICTED | `app/Infrastructure/Support/TransientLogThrottle.php:24` stores throttle state in cache; `config/cache.php:61-65` defines a Redis store |
| Caching is a targeted exception (HelpScout only) per `docs/architecture-overview.md:197` | CONTRADICTED | Additional consumers: `app/Infrastructure/BingAds/BingAdsSessionManager.php`, `app/Infrastructure/Linnworks/LinnworksSessionManager.php` (OAuth sessions), `app/Infrastructure/ClickUp/Cache/ClickUpUserCache.php` (7-day user lookup), `app/Infrastructure/Locking/CacheLockManager.php` |
| Default cache store | CONFIRMED `database` | `config/cache.php:30`, `.env.example:87` |

What the README's argument actually defends still holds: there is no general read-through cache in front of synced PostgreSQL data. The caching that exists is targeted at OAuth session tokens, HelpScout read responses (5-minute and 7-day TTLs in `app/Application/HelpScout/Services/CachingHelpScoutService.php:73-257`), a ClickUp user lookup, distributed locks, and alert throttling. ADRs 0003, 0005 and 0006 in `docs/adr/` document this caching as established infrastructure. The decision section survives only if reframed as "targeted caching, no general cache layer". The architecture overview line 197 needs the same correction.

### 2.2 CI and testing claims (README lines 173-217)

| Claim | Verdict | Evidence |
|-------|---------|----------|
| 7-job pipeline with docs-only change detection | CONFIRMED (nuance) | `.github/workflows/ci.yml` jobs at lines 23, 40, 88, 183, 218, 269, 315; filter in `.github/filters.yml:5-20`. Mutation jobs are gated on target branch `main` only, not on change detection (`ci.yml:273, 319`) |
| PostgreSQL 17 + Redis 7 in CI | CONFIRMED | `ci.yml:113`, `ci.yml:128` |
| Integration tests excluded from CI | CONFIRMED | `ci.yml:180` runs `--exclude-group=integration`; 16 files carry `#[Group('integration')]`, two of them outside `tests/Integration/` |
| "Containerised PostgreSQL for CI is a known improvement" (line 216) | CONTRADICTED (stale) | CI already provisions containerised PostgreSQL (`ci.yml:112-125`). The Supabase auth schema is mocked for plain PostgreSQL by `database/migrations/2025_12_23_190003_adopt_auth_schema.php:9-13`. The actual blocker is tests asserting against live-synced production rows, e.g. `tests/Integration/Catalog/VatReliefFilterGroupGuardTest.php:32-41` |
| Domain MSI threshold | 90% is correct; 85% is stale | `Makefile:292` passes `--min=90`; `ci.yml:270` job name says 90%. README lines 96 and 179 say 85%, line 211 says 90% |
| Application MSI 70% | CONFIRMED | `Makefile:295-300` |
| Mutation jobs informational on PRs to main | CONFIRMED | `ci.yml:273-274, 319-320` (`continue-on-error: true`) |
| Coverage targets Domain 90% / Application 70%, pure-delegation use cases excluded | CONFIRMED | `codecov.yml:13-20, 33-37`; `phpunit.xml:66-73` |
| Pre-commit and pre-push hook contents | CONFIRMED | `config/git-hooks.php:21-25, 37-41` |
| Code style, Pest parallel, security audit, Psalm taint jobs | CONFIRMED | `ci.yml:40-85, 88-180, 183-215, 218-262` |

Precise wording for the limitation: unit and feature suites run in CI against containerised PostgreSQL; the integration suite is excluded because several tests depend on production data synced from external systems, not because CI lacks a database.

### 2.3 Headline numbers

| Claim | README | Verified | Verdict | Reproduction |
|-------|--------|----------|---------|--------------|
| Third-party integrations | 11 | 11 | CONFIRMED | `app/Infrastructure/*` client dirs plus `config/services.php:43` |
| Queued jobs | 73 | 81 | DRIFT | `find app -name "*Job.php"` gives 83, minus 2 abstract bases |
| Scheduled tasks | 57 | 66 | DRIFT | `grep -rE "Schedule::(job\|call\|command)\(" app/Providers/Schedule/*.php` |
| Schedule providers | 10 | 13 | DRIFT | `ls app/Providers/Schedule/*.php`; all registered in `bootstrap/providers.php:75-87` |
| Custom PHPStan rules | 27 | 27 | CONFIRMED | `app/DevTools/PHPStan/Rules` and `phpstan-custom-rules.neon:32-65` |
| Job rules under `Rules/Jobs/` | 6 | 6 | CONFIRMED | directory listing |
| Scoped rule files | 28 | 27 | DRIFT | `.claude/rules/*.md` is 29 files; two are meta-docs without frontmatter |
| Plan documents | 162 | 198 | DRIFT | `find .ai/plans -name "*.md"` (local, git-ignored, grows continuously) |
| Implementation logs | 132 | 182 | DRIFT | `find .ai/implementation-logs -name "*.md"` |
| ShopWired clients | 14 | 17 | DRIFT | `find app/Infrastructure/Shopwired -iname "*Client.php"` (16 under `Clients/` plus root client) |
| Linnworks clients | 10 | 10 | CONFIRMED | same method |
| HelpScout clients | 5 | 5 | CONFIRMED | same method |
| Queue priority tiers | 5 | 5 | CONFIRMED | `config/horizon.php:214-217` |
| Railway services | 3 | 3 | CONFIRMED | `docs/architecture-overview.md:73-75`; no machine-readable Railway manifest in the repo |
| Type coverage target | 99% | 99% | CONFIRMED | `phpstan.neon:142-145` |
| Mutators | 190+ | 185 | DRIFT | `vendor/pestphp/pest-plugin-mutate/src/Mutators` (189 files including 3 abstract bases and 1 trait) |

Every drift except mutators and rule files is upward, consistent with the README having been written months ago and the codebase growing since. None is a fabrication. Decision taken (D4): drift-prone counts become approximations such as "70+ queued jobs"; exact figures stay only where the number is stable (custom rules, integrations, tiers). Railway note: the three application services are confirmed, and a Railway-hosted Redis runs alongside them; whether to count it as a fourth service is a wording choice for the rewrite.

Caching pointers in section 2.1 were re-checked directly by the reviewer after the verifier pass: `app/Providers/CacheServiceProvider.php:47-107` binds two cache abstractions, a lock manager and the log throttle; `app/Application/HelpScout/Services/CachingHelpScoutService.php:36-39, 78-253` documents and implements 7-day and 5-minute TTL reads; a consumer grep hits 23 files, most of them transports and error handlers using the throttle, with data caching confined to HelpScout, ClickUp, and the Bing and Linnworks session managers.

### 2.4 Public surface claims (README lines 74-77)

| Claim | Verdict | Evidence |
|-------|---------|----------|
| "a small internal tool" | CONTRADICTED as an absolute | Internal dashboard is real: 22 controllers behind Supabase JWT with MFA enforced and an approval gate, 60 requests/min per user (`routes/api.php:131-279`, `app/Presentation/Http/Auth/Middleware/ValidateSupabaseJwtMiddleware.php:93-96`, `app/Providers/RateLimitServiceProvider.php:52-69`). But a public surface exists alongside it |
| "not customer-facing" | CONTRADICTED | Three unauthenticated storefront endpoints at 5 requests/min per IP with a honeypot on the contact form: `POST api/contact`, `POST api/checkout/snapshot`, `POST api/display-number` (`routes/api.php:57-81`, `RateLimitServiceProvider.php:47`). `config/cors.php:8-16` names them as the public endpoints called cross-origin from the storefront. One unauthenticated feed redirect at `routes/web.php:24-26` with GUID obscurity and no rate limit |
| "3-4 users" | UNVERIFIABLE | Users live in Supabase; no seeder or config enumerates production users |
| Caching rationale depends on the public surface | NOT AFFECTED | Two of the three public endpoints record submitted data and return nothing; the display-number endpoint computes a phone assignment per visit. None reads cached data. The user confirms no public endpoint needs caching |

Additional surface facts for the rewrite: two inbound webhook groups (ShopWired with HMAC-SHA256, Twilio with its own HMAC-SHA1 scheme) at 300 requests/min per IP; ops and Horizon endpoints behind HTTP basic auth outside the web middleware group. Side findings outside README scope: a `global` rate limiter at `RateLimitServiceProvider.php:43` is defined but unreferenced; `docs/architecture-overview.md:15` models only a staff actor in the C4 context, with no storefront visitor.

## 3. Findings: Structure

Numbered for reference in the clarification session. Priority reflects impact on the reader.

**S1. The first screen sells the business, not the engineering.** [RENDER] The visible content above the fold is title, badges, an intro paragraph that leads with the business description, a status callout, then a table of contents. The strongest content (invariants table, decision records, testing philosophy) sits in sections 1, 2 and 5. High priority.

**S2. No routing for a first-time reader.** [README] The README describes the system but never tells a reader where to start. A "Where to start" section listing a handful of files to open (the invariants table, one custom PHPStan rule, one use case with its job, one ADR, the testing strategy doc) is the highest-leverage addition for a time-limited reviewer. Whether this is phrased as a neutral overview aid or as an explicit portfolio pitch is a pending decision (D1). High priority.

**S3. The table of contents is redundant.** [RENDER] GitHub renders its own outline for the file. The manual list at lines 11-20 costs a screen and adds nothing. Medium priority.

**S4. The best content is buried.** [README] The enforced-invariants table (line 53) is the most verifiable claim in the document. The philosophy sentence "test what static analysis can't catch" (line 184) is the clearest statement of approach. Both belong near the top. In the Architecture section the table could lead and the diagram follow. Medium priority.

**S5. "What's next" is the longest low-value section.** [README] Lines 219-247, roughly a seventh of the file, describe work not in the repo, including a four-step implementation sequence for delivery dates. The H3 headings there appear in GitHub's outline as siblings of real sections. Recommend three or four one-line items, or a link to a roadmap doc. Medium priority.

**S6. Known Limitations undersells ADRs.** [VERIFIED] Line 217 says only one ADR has been formalised. `docs/adr/` holds ten, two with explicit accepted status and dates. Flip to a strength and link the folder. High priority because it is a false negative about the codebase.

**S7. Architecture overlap with `docs/architecture-overview.md` is acceptable.** [README] The overview carries a fuller C4 component diagram and data flows; the README's four-node diagram is a reasonable summary. No change needed beyond keeping the existing link.

## 4. Findings: Messaging and Positioning

**M1. The AI-usage story is told twice with different emphasis.** [README] Lines 94-100 say tests were the only area fully delegated and production code was collaborative. Line 198 says Claude Code is the primary implementation tool and AI handles implementation velocity. A sceptical reader will probe the gap. State the division of labour once: human owns architecture, design, decisions and review; AI implements inside the rule files and linters; tests fully delegated with mutation score as the floor. It belongs in the workflow section. Key Engineering Decisions should stay technical. High priority.

**M2. "Portfolio showcase" undercuts "Production backend".** [README] Line 9 reframes the system as a showcase directly under a paragraph calling it a production system. Suggested wording keeps production as the identity: published read-only for reference. Medium priority. Linked to D1.

**M3. Intro paragraph has a fragment and leads with the wrong thing.** [README] "All behind a mechanically enforced Clean Architecture" (line 7) is not a sentence. Recommended order: what it is in one clause, the distinctive claim (architecture enforced by tooling rather than convention), then counts as evidence. Keep "built for correctness and maintainability in a small team, not horizontal scale". The identity sentence should also name both audiences (staff dashboard and storefront endpoints) per M8. Medium priority.

**M4. "No caching layer" must be re-argued, not just reworded.** [VERIFIED] See sections 2.1 and 2.4. The heading is contradicted by three ADRs and the service provider. Two of the three rationale bullets ("internal tool with 3-4 users", "not customer-facing") are false as statements of system scope, though the caching conclusion is unaffected because the public endpoints are write-only or per-visit computations. The surviving argument is: no general cache in front of synced data, because PostgreSQL already holds a local copy of every remote system; targeted caching where an external contract demands it (OAuth session tokens, HelpScout read responses, alert throttling). The Tech Stack row describing Redis as queue-only needs the same fix, as does `docs/architecture-overview.md:197`. High priority.

**M8. The system is no longer internal-only and the README does not say so.** [VERIFIED] See section 2.4. The codebase serves two audiences with distinct auth and rate-limit regimes: an approval-gated staff dashboard behind Supabase JWT with MFA, and anonymous storefront endpoints behind IP throttling and a honeypot, plus two signed webhook channels and basic-auth ops routes. The README's intro, the caching rationale and the Presentation row all assume a single internal audience. The two-surface design is a stronger engineering story than anything in Key Decisions today (differentiated auth per surface, deliberate stateless routing outside the web middleware group at `bootstrap/app.php:31-36`). High priority. Gates D9.

**M5. Key Engineering Decisions are all integration-shaped.** [README] The three technical decisions are ShopWired webhooks, HelpScout SDK, and caching. One or two system-design decisions would show breadth: the five-tier queue and three-service split, the checked-exception discipline, or the DatabaseGateway ban. The ADR folder is a ready source (0007 template-method drift syncs, 0010 per-platform adapters). Low priority.

**M6. MSI threshold inconsistency.** [VERIFIED] Domain MSI is 85% at lines 96 and 179 and 90% at line 211. Config says 90%. Fix the two stale mentions. High priority because it is a visible self-contradiction.

**M7. CI versus integration-test wording.** [VERIFIED] See section 2.2. Line 216's second sentence is stale. Replace with the precise blocker. Medium priority.

## 5. Findings: Writing Quality and Rendering

**W1. Integrations diagram renders as a scroll wall.** [RENDER] With seven subgraphs the left-to-right graph stacks vertically to roughly three screens, with eleven edges fanning into one node and labels overlapping lines. A table (service, protocol, auth, sync pattern, client count) is denser and carries the per-service detail better. If a diagram stays, drop the subgraphs. Medium priority.

**W2. Layer diagram has a label collision.** [RENDER] The "implements" edge label crosses the dotted "reads" edge in the centre. The Presentation-reads-Domain edge is the least important and causes the crossing. Fill styles set an explicit text colour, so both themes render correctly. Low priority.

**W3. Numbers repeated across sections.** [README] The 27 custom rules appear at lines 24, 51 and 184; the 162 plan documents at 200 and 217; the 57 scheduled tasks at 7 and 171; MSI thresholds at 96, 179 and 211. The paragraph at line 51 restates rows of the table directly below it. Say each number once where it carries the most weight. Medium priority.

**W4. Smaller nits.** [README]
- Tech Stack column header "Layer" is wrong for rows like Language and Framework; "Concern" fits.
- The bold "Enforcement tools" line (line 49) duplicates the Tech Stack table.
- The tiered-schedule paragraph (line 171) hangs below the diagram with no heading.
- Arrow characters are used inconsistently in headings and table cells.

**W5. Repository landing page is empty.** [RENDER] GitHub's About box shows no description, website or topics. A one-line description plus topics (laravel, clean-architecture, phpstan, php) is cheap and is what a reader sees before the README. An AI-agent account appears in the contributors list, consistent with the AI disclosure but worth knowing is visible. Low priority, outside the README file.

## 6. Withdrawn Findings

- **CI badge points at `main` while the default branch is `develop`.** Withdrawn. The badge reports production health, which is the correct signal.
- **Pest-in-CI versus Supabase contradiction.** Reduced to M7. Both statements are true for different suites; only the "known improvement" sentence is stale.

## 7. Decisions

Resolved 2026-09-07 in a one-decision-at-a-time session. Each entry records the outcome; the plan is derived from these.

- **D1. Concept — DECIDED.** Overview voice, not portfolio framing. A neutral "Where to start" section sits near the top; no explicit showcase language. The status line becomes "Published for reference. Not accepting external contributions or issues." Gates S1, S2, M2.
- **D2. Caching decision — DECIDED.** Re-argued in place under a heading along the lines of "No general cache, targeted caching where a contract demands it". The PostgreSQL-as-local-copy reasoning stays; the three rationale bullets are replaced by the surviving argument, naming the three targeted uses (OAuth session tokens, HelpScout read responses, alert throttling), and linking ADR 0005 and the new ADR 0011. The stale sentence at `docs/architecture-overview.md:197` and the Tech Stack Redis row are corrected in the same change. Gates M4.
- **D3. Roadmap — DECIDED.** Trimmed in place to three or four one-line items; no H3 headings, no step sequences. Gates S5.
- **D4. Numbers policy — DECIDED.** Exact counts are kept for integrations, custom PHPStan rules, queue tiers, Railway services (3 plus hosted Redis), the type-coverage target, and MSI thresholds. Everything else is approximated. Plan-document and implementation-log counts are approximated ("around 200 / 180"), stated once in Development Workflow, with a note that they live in a local AI workspace outside the public repo. Per-integration client counts are approximated or dropped from the table. Gates section 2.3 and W3.
- **D5. Integrations diagram — DECIDED.** The Mermaid diagram is replaced by a table (service, role, protocol, auth, sync pattern), one row per integration including call tracking. Gates W1.
- **D6. AI-usage statement — DECIDED.** A single statement in Development Workflow: human owns architecture, design, decisions and review; AI implements within the rule files and linters; tests are fully delegated with mutation score as the floor; the CI review gate is informational. The Key Engineering Decisions entry on AI/mutation testing is removed — mutation philosophy already lives in Testing Strategy. Gates M1.
- **D7. Repository metadata — DECIDED.** In scope as a manual step. The plan proposes a one-line About description and topic list; the user applies them in GitHub settings. Gates W5.
- **D8. Post-rewrite additions — DECIDED.** Call tracking is added as the twelfth integration row; the AI review gate is added to the CI table; the conversion-upload adapter seam (ADR 0010) is added as a Key Engineering Decision. Dead-code removal, the materialised view, and the abstract job base are not added. Gates section 9.
- **D9. Two-audience positioning — DECIDED.** Becomes a Key Engineering Decision: one application serving the Admin Dashboard and the public endpoints, with auth and rate limiting per surface, plus signed webhooks and basic-auth ops routes. The intro names both audiences. `docs/architecture-overview.md`'s C4 context gains a storefront visitor actor in the same change. Gates M8, M3, M4.

**Follow-up decisions from the session:**

- Terminology: canonical names are **Admin Dashboard** (the Next.js staff app; avoid "staff dashboard", "frontend application") and **public endpoints** (the anonymous per-IP-throttled routes called cross-origin from the storefront; avoid "storefront endpoints"). Recorded in `CONTEXT.md`.
- Key Engineering Decisions final set, five entries: caching (reframed), webhook partial-save, HelpScout hybrid, two-audience design, conversion-upload adapter seam.
- ADR 0011 "No general cache in front of synced data" created in this session; the README caching section links it. No ADR for the two-audience design.
- "Where to start" points at docs (architecture overview, ADR index, testing strategy) plus three or four code entry points named at directory or class level, never file:line.
- Known Limitations keeps two items: no distributed tracing; integration tests need production-synced rows and run locally, while unit and feature suites run in CI against containerised PostgreSQL and Redis. The ADR line flips to a strength (ten ADRs, with an index link).
- Next step: implementation plan derived from this section and section 8.

## 8. Suggested Section Order

Total length is not the problem; ordering is. The first forty lines should serve a skim and everything below serves a deep read.

1. Title, badges, two-sentence identity (M3)
2. Where to start: a short reading path (S2)
3. Architecture: invariants table first, then diagram, then link to the overview (S4)
4. Key engineering decisions, technical only, caching reframed (M4, M5)
5. Integrations as a table (W1)
6. Testing strategy, philosophy sentence first
7. Development process including one AI-usage statement (M1)
8. CI/CD pipeline
9. Known limitations with ADRs flipped to a strength (S6, M7)
10. Roadmap in three or four lines (S5)

## 9. Changes Since the Last Rewrite

The README was substantively rewritten at `ced310d3` (2026-05-19, COR-153) and last extended at `4edbee0d` (2026-05-25). The range `4edbee0d..HEAD` holds 14 merge commits plus 36 squash-merged PR commits. Inventory below; judgement on inclusion is D8.

### 9.1 Candidates for the README

| Change | Evidence | Where it would land |
|--------|----------|---------------------|
| **Call tracking module** (Twilio inbound call webhooks, attribution, lead conversion) | `app/Infrastructure/CallTracking/`, `config/call-tracking.php`, `app/Providers/CallTrackingServiceProvider.php`, schedule provider, three jobs, ADR 0004. Inbound webhook only, no outbound client or SDK | Integrations (a twelfth external touchpoint, same kind as ShopWired webhooks); intro count |
| **AI review gate in CI** (informational, docs-only skip, pinned reviewer) | `.github/workflows/review-gate.yml`, PRs #941 and #943 | CI/CD table and the AI-usage statement (M1). The README currently says code review is human-driven; the gate is informational, so the claim survives with a clause |
| **Seven new ADRs** (0004 to 0010) | `docs/adr/` | Known Limitations flip (S6); source for M5 |
| **Dead-code removal with ADR** (Linnworks purchase-order write layer, dead interface methods) | ADR 0009, PRs #931 and #933, `docs/api-contracts/deleted-shopwired-order-endpoints.md` | Optional Key Engineering Decision showing maintenance discipline (M5) |
| **Conversion upload adapter seam** (per-platform adapters, 5 use cases and jobs collapsed to 3) | ADR 0010, PR #947 | Optional Key Engineering Decision (M5) |
| **Transient log throttle** (Redis-backed alert suppression) | ADR 0006, PR #921 | Part of the caching reframe (M4) |
| **Laravel 13 upgrade**, Google Ads API v25 | PRs #965, #964 | Already reflected in Tech Stack; no change |
| **Full-text product search** on a materialised view, migration rule for view recreation | ADR 0008, PR #937 | Optional; Tech Stack database row could mention materialised views |
| **Abstract job base class** | `app/Infrastructure/Jobs/AbstractJob.php` | Optional clause in the job-rules invariant row |

### 9.2 Not README-worthy

Dependency and CVE patch bumps, harness and Codex tooling files, Makefile fixes, catalog bug fixes, the environment-variable rename from `CACHE_DRIVER` to `CACHE_STORE`.

### 9.3 Roadmap drift

None of the four "What's next" items (image pipeline, QuickBooks, delivery dates, B2B identification) appears in the range. Call tracking, which was built, was not on the roadmap. This supports S5: the roadmap section does not predict what gets built and goes stale quickly.

### 9.4 Customer-facing claim

Resolved: see section 2.4 and finding M8. The range added the call-tracking display-number endpoint and the basket-recovery dashboard endpoint (PR #907, staff-side) on top of the pre-existing public contact-form and checkout-snapshot endpoints.
