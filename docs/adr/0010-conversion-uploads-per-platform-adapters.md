# ADR 0010: Conversion Uploads via Per-Platform Adapters

## Status

Accepted (2026-07-14)

## Context

The offline-conversion upload pipeline baked the ad platform (Google / Bing) into class names across every layer:

- **5 Process use cases** (`ProcessLeadConversionUseCase`, `ProcessBingLeadConversionUseCase`, `Process{Google,Bing}CallLeadConversionUseCase`, quote Google-only) and **5 jobs** — near-duplicate pairs differing only by which platform client they called.
- **Twin DTOs + interfaces** — `{Google,Bing}ConversionUploadDTO`, `{Google,Bing}AdsConversionInterface` — structurally identical apart from the click-ID field name (`gclid` vs `msclkid`).
- **`if gclid … if msclkid …` fan-out** hand-written in each Submit use case.

Adding a third platform (e.g. Facebook via `fbclid`) meant touching all four layers. Worse, the quote path hard-coded `AdPlatform::Google`: an msclkid-only submission created a Google action row that the job could only fail (no gclid to upload), because nothing modelled "Bing does not support quote conversions."

## Decision

Collapse the platform axis behind **one seam**, `AdPlatformConversionAdapterInterface` (`platform()`, `supports(ConversionType)`, `extractClickId(MarketingAttribution)`, `upload(ConversionType, ConversionUploadDTO)`). Each platform is one `*ConversionAdapter` wrapping the existing concrete service.

- **Process use cases 5 → 3, jobs 5 → 3**, each parameterised by an `AdPlatform` argument. `AdPlatform` moves into the 3 Command objects; dispatcher interfaces collapse to one method per conversion type.
- **One `ConversionUploadDTO`** (`clickId`, `email`, `convertedAt`, `value?`, `phone?`) replaces both twins.
- **`AdPlatformAdapterResolverService`** holds `list<AdPlatformConversionAdapterInterface>` and answers `eligiblePlatforms(type, attribution)` (supports type AND click ID present), `platformsWithClickId(attribution)`, and `adapterFor(platform)`. Fan-out iterates eligible adapters instead of branching on platform name.

## Key Design Points

- **Job uniqueness must include the platform.** `ShouldBeUnique::uniqueId()` returns `"{id}:{platform->value}"`. Without the platform suffix the Google and Bing jobs for one submission would dedupe each other and one upload would be silently dropped.
- **Eligibility is the three-state signal.** `platformsWithClickId` empty ⇒ no ad click at all ⇒ `InsufficientDataException` (unchanged). `eligiblePlatforms` empty while a click ID exists ⇒ the platform can't receive this conversion (msclkid-only quote) ⇒ **error log + zero action rows**, not a doomed upload. `eligible ⊊ withClickId` ⇒ info log, proceed with the rest.
- **Validation timing is behaviour-critical.** `extractClickId` is a raw null-check (runs at submit time, where only presence was ever checked). Click-ID VO format validation (`Gclid::from` / `Msclkid::from`) stays in the adapter's `upload()` (job time) so a malformed stored click ID fails the async job, never the staff HTTP submit.
- **Bing supports only `lead_received`.** `BingAdsConversionAdapter::supports()` returns false for `quote_issued`; Google supports both. This is the single place platform capability is declared.
- **DI resolves both adapters eagerly at submit time.** `ConversionServiceProvider` builds the resolver with an explicit `[Google, Bing]` adapter list, so a Submit use case now constructs both conversion services (and runs their config factories) rather than only the dispatched platform's. Acceptable because both platforms are always configured in this deployment; a future optional-platform deployment would need lazy adapter resolution.

## Deploy Note

Deleting the Bing/call job shells and adding the `AdPlatform` constructor property breaks unserialization of any in-flight queued `Process*Conversion*` jobs (default queue, `retryUntil` +14h, backoff up to 12h). Before deploying: drain the Horizon `Process*Conversion*` jobs, or accept that the failure handler marks the stragglers failed. `ShouldBeUnique` locks (`uniqueFor` 300s) expire harmlessly.

## Alternatives Rejected

- **Container-tagged adapter collection** instead of an explicit list — hides fan-out membership and order behind a tag string; the explicit `[Google, Bing]` array keeps it visible at the binding site.
- **Move click-ID VO validation into `extractClickId`** — would surface a malformed-click-ID failure inside the staff HTTP submit instead of the retryable upload job, changing observable behaviour.
