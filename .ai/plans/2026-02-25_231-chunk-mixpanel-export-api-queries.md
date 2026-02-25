# Chunk Mixpanel Export API Queries for Large Date Ranges

## Context

When dispatching `SyncOrdersToMixpanelJob` with large date ranges (25+ days), the Export API query times out at 30s with ~3.9MB of JSONL partially received. This means **manual backfills and catch-up syncs fail**, requiring us to manually split into weekly chunks via `railway ssh`. The nightly (28h) and weekly (14-day) schedules work fine, but ad-hoc dispatches with broader ranges don't.

**Goal:** Make the sync resilient to any date range by chunking the Export API query internally. Also future-proofs the scheduled weekly run against peak-period volume growth.

## Approach: UseCase-Level Chunking

Chunk in `SyncOrdersToMixpanelUseCase::getExistingHashes()`. This is orchestration logic — the UseCase already manages date adjustments (24h lookback, 4h ingestion buffer). The client keeps its simple single-request contract. No interface changes needed.

This matches the existing pattern in `SyncProductRatingsUseCase` which already batches at the UseCase level.

## Changes

### 1. Add `chunk()` to `DateRange` value object

**File:** `app/Domain/ValueObjects/DateRange.php`

Add a method that splits the range into sub-ranges of N days:

```php
/**
 * @param positive-int $days
 * @return list<self>
 */
public function chunk(int $days): array
```

- Day-aligned boundaries: each chunk starts at the `from` time, ends at the same time + N days (capped at `$this->to`)
- Range smaller than chunk size returns `[self]` (single element)
- Final chunk may be shorter than N days

### 2. Modify `getExistingHashes()` in UseCase

**File:** `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php`

```php
private const int EXPORT_CHUNK_DAYS = 7;
```

- Create `DateRange` from export window, call `->chunk(self::EXPORT_CHUNK_DAYS)`
- Single chunk = direct call (no overhead for normal nightly runs)
- Multiple chunks = iterate, merge hashes using array keys for O(1) dedup
- Log chunk count at info level, per-chunk progress at debug level
- Fail-fast: any chunk failure aborts the entire sync (can't deduplicate with partial data)
- Empty chunks **should** throw — a 7-day window with zero events means something is broken (frontend tracking, Mixpanel outage). No special handling needed.

**Why 7 days, not 14:** Current volume is ~156KB/day of JSONL. At 14 days during peak (2-3x volume), that's 4-6MB — back in timeout territory. 7 days gives ~1MB normally, ~2-3MB peak. Safe margin.

**Impact on existing schedules:**
- Nightly 28h + 24h lookback = ~3 days → single chunk, no change
- Weekly 14 days + 24h lookback = 15 days → 3 chunks, protects against peak-period timeouts

### 3. Tests

**`tests/Unit/Domain/ValueObjects/DateRangeTest.php`** — Add cases for `chunk()`:
- Range < chunk size → single chunk
- Range spanning multiple chunks → correct count and boundaries
- Single-day range → single chunk
- Large range (90 days, 7-day chunks) → 13 chunks

**`tests/Unit/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCaseTest.php`** — Add cases:
- Short range makes single `getExistingOrderHashes` call
- Long range makes multiple calls with correct date ranges
- Hashes from multiple chunks are merged and deduplicated
- Failure in any chunk propagates (fail-fast)

## Out of Scope

- **Import batching** (`importOrders` single POST) — not the current bottleneck, separate concern
- **HTTP timeout increase** — chunking is the right fix; increasing timeout just delays the problem
- **Streaming JSONL parsing** — current in-memory approach is fine for 14-day chunks

## Verification

```bash
make test-unit                    # All unit tests pass
make lint                         # Linters pass
# Then on prod: dispatch a 30+ day range and confirm it completes
```
