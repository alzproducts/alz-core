# Implementation Log: Google Ads Offline Conversion Tracking — Infrastructure Layer

**Linear Issue**: COR-136
**Plan Document**: .ai/plans/2026-05-16_COR-136-google-ads-offline-conversion-tracking.md
**Branch**: feature/cor-136-feat-google-ads-offline-conversion-tracking-infrastructure
**Status**: In Progress
**Started**: 2026-05-16
**Completed**: —

## Overview

Phase 1 — Infrastructure-only. Adds capability to upload offline conversions (Lead Received, Quote Issued) to Google Ads via the `ConversionUploadService` API. Each upload sends a gclid + SHA-256 hashed email. Application/Presentation wiring is Phase 2.

## Decision Log

### 2026-05-16
- **Decision**: Follow plan verbatim — single transport class (`GoogleAdsTransport`) gains `uploadClickConversion()` next to the existing `search()`.
  - **Why**: Reuses existing exception translation helpers (`handleApiException`, etc.); two SDK services (`GoogleAdsServiceClient`, `ConversionUploadServiceClient`) but one transport.
- **Decision**: `ConversionType` placed in new `App\Domain\Conversion\Enums\` namespace (no existing `Conversion` bounded context).
  - **Why**: Business concept (types of conversions tracked), not Google-specific — keeps it framework- and vendor-agnostic.
- **Decision**: `?Money $value` parameter on `uploadConversion()`; net value extracted via `Money::toNet()`.
  - **Why**: Google Ads conversion `value` is documented as net (excl. VAT); nullable lets callers fall back to the conversion action's default value.

## Deviations from Plan

- **`ClickConversionData` VO introduced** (not in plan): PHPStan `alz.excessiveParameterCount` (max 4) flagged `uploadConversion()` at 5 params. Introduced `App\Domain\Conversion\ValueObjects\ClickConversionData` to group gclid, email, convertedAt, value. Validation (non-empty assertions) moved from client into the VO constructor.
- **`rejectEmpty()` helper on `GoogleAdsConfig`** (not in plan): Constructor exceeded 40-line limit after adding 2 new fields. Extracted repetitive `if ($x === '') throw` blocks into a static helper.
- **`withConversionActionIds()` wither on config** (not in plan): Enables `createConversionConfig()` to stay under 20 lines by calling `createConfig()->withConversionActionIds(...)` rather than extracting a shared `readBaseConfigFields()` helper (which would break the existing baseline suppression on `createConfig()`).

## Progress

- 2026-05-16 — Branch created from `origin/develop`; Linear status → In Progress.
- 2026-05-16 — Verified SDK classes present in `vendor/googleads/google-ads-php/.../V22/`.
- 2026-05-16 — Initial implementation complete (5-param interface).
- 2026-05-16 — Tests: 16 client + 7 transport tests written and passing.
- 2026-05-17 — Lint fix: introduced `ClickConversionData` VO, `rejectEmpty()`, `withConversionActionIds()`, reverted `readBaseConfigFields()` extraction. All 6 PHPStan errors resolved.
- 2026-05-17 — Simplify: all 3 review agents confirmed code is clean (findings were false positives given baseline constraints).
- 2026-05-17 — Sweep: 3411 tests pass, all 5 linters green, no issues found.

## Blockers / Open Questions

(none)

## Technical Notes

- `createConfig()` is baselined at 36 lines (`phpstan-complexity-baseline.neon`). Cannot rename or extract its body without breaking the baseline match.
- `GoogleAdsConfig` is `final readonly` — `withConversionActionIds()` creates a new instance (no mutation).
- SDK's `getConversionUploadServiceClient()` creates a new client per call (no caching in SDK). Acceptable for single-conversion-per-job pattern; if Phase 2 introduces batching, use the `repeated conversions` field on the request instead of looping.

## PR Notes

### What
Adds Infrastructure-layer capability to upload offline conversions to Google Ads (`ConversionUploadService`). Introduces `ConversionType` enum, `ClickConversionData` VO, `GoogleAdsConversionClientInterface`, `GoogleAdsConversionClient`, and extends `GoogleAdsConfig`, `GoogleAdsTransport`, `GoogleAdsClientFactory`, `GoogleAdsServiceProvider`, and `config/google-ads.php`.

### Why
Frontend captures Lead Received and Quote Issued events that should be attributed back to Google Ads clicks. Sending both gclid and hashed email maximises match rate. Application/Presentation wiring is Phase 2.

### Key Decisions
- One transport (`GoogleAdsTransport`) handles both `search()` and `uploadClickConversion()` — shares exception translation helpers.
- `ConversionType` lives in `App\Domain\Conversion\Enums` (new bounded context).
- `ClickConversionData` VO groups conversion event data (4 params) with construction-time validation.
- Action ID resolution lives in infra — callers pass the enum, never raw Google action IDs.
- `partial_failure=true` on every request; per-conversion errors translate to `InvalidApiRequestException`.
- `GoogleAdsConfig::withConversionActionIds()` enables config composition without breaking the existing `createConfig()` baseline.

### Testing
- 23 new tests (16 client + 7 transport)
- `make lint` — all 5 linters pass
- `make test` — 3411 tests pass
