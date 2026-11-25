# ADR 0010: Conversion Uploads via Per-Platform Adapters

Conversion uploads reach ad platforms only through `AdPlatformConversionAdapterInterface`, one adapter per platform. Use cases, jobs and the upload DTO are platform-neutral, and `AdPlatformAdapterResolverService` picks eligible adapters. Platform-named twins of every class had made each new platform a four-layer change and let quote uploads target a platform that could not receive them.

- **Job uniqueness includes the platform.** `uniqueId()` is `"{id}:{platform->value}"`; without it the platforms' jobs for one submission dedupe each other and an upload is silently dropped.
- **Eligibility has three states.** No click ID: `InsufficientDataException`. A click ID but no eligible platform: error log and zero action rows. Some platforms ineligible: info log, proceed with the rest.
- **Validation timing is behaviour-critical.** `extractClickId` is a null-check at submit time; format validation stays in `upload()`, so a malformed stored click ID fails the job, never the staff HTTP submit.
- **`supports()` is the single place platform capability is declared.** Bing supports only `lead_received`.
- **DI resolves every adapter eagerly at submit time.** An optional platform would need lazy resolution.

## Considered Options

- **Container-tagged adapter collection:** rejected. It hides fan-out membership and order; the explicit list shows them at the binding site.
- **Click-ID validation in `extractClickId`:** rejected. The failure would surface in the staff HTTP submit instead of the retryable job.
