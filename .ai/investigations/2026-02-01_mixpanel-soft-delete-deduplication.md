# Mixpanel Soft-Delete Causing Order Sync Deduplication False Positives

**Date:** 2026-02-01
**Participants:** Tom, Claude
**Status:** Waiting (3 days until hard deletion)

## Summary

4 of 9 credit orders from Jan 25-Feb 1 were missing from Mixpanel despite existing in the database. Investigation revealed Mixpanel's Export API returns soft-deleted events that are hidden from the UI. Our deduplication logic sees these "ghost" hashes and skips orders, thinking they already exist. Resolution: wait for Mixpanel's 7-day hard deletion, then re-sync.

## Symptoms

- 9 credit orders in `shopwired.orders_deduplicated` since Jan 25th
- Only 5 credit orders visible in Mixpanel UI (filtered by non-cash payment methods)
- 4 missing orders all from Jan 26th: `111478`, `111479`, `111480`, `111490`

## Investigation

### Step 1: Verified orders exist in production database

```sql
SELECT o.reference, o.order_placed_at, c.is_credit_enabled
FROM shopwired.orders_deduplicated o
JOIN shopwired.customers c ON c.external_id = o.customer_id
WHERE c.is_credit_enabled = true
AND o.order_placed_at >= '2026-01-25'
```

Result: All 9 orders present with `is_credit_enabled = true`.

### Step 2: Queried Mixpanel Export API for Jan 26th

```php
$client->getExistingOrderHashes($from, $to);
```

Result: **26 hashes returned** — more events than visible in UI.

### Step 3: Checked if credit order hashes exist in Export API response

Computed SHA-256 hashes for references `111478`, `111479`, `111480`, `111490` and checked against Export API response.

Result: **All 4 hashes found** — deduplication would skip them.

### Step 4: Examined raw event properties

```php
// For each of the 4 orders:
source: backend-sync
payment_method: admin
$import: YES
distinct_id: [valid customer IDs]
```

Result: Events **were** imported by our backend sync previously.

### Step 5: Researched Mixpanel deletion behavior

From Mixpanel docs:
> "When you submit a deletion request, we hide your data immediately from your project... We call this 'soft deletion', an interim phase before our 'hard deletion'..."

> "If you re-import data while the data is soft deleted with the same `$insert_id`, our deduplication systems may keep the old (deleted) event and toss the new event."

## Root Cause

1. The 4 orders were previously synced to Mixpanel (Jan 26th)
2. Someone deleted them via Mixpanel's Data Deletion tool
3. **Soft-deleted events remain in Export API** but are hidden from UI
4. Our `SyncOrdersToMixpanelUseCase` queries Export API for deduplication
5. It sees the soft-deleted hashes and skips the orders
6. Re-imports with the same `$insert_id` are rejected by Mixpanel's dedup system

## Resolution

**Wait 3 days** (until ~Feb 4th) for Mixpanel's hard deletion (7-day window from deletion request). After hard deletion:
- Hashes will be cleared from Export API
- `$insert_id` collision will be resolved
- Re-running sync will successfully import the events

```php
// After Feb 4th:
SyncOrdersToMixpanelJob::dispatch(
    new DateTimeImmutable('2026-01-25 00:00:00'),
    new DateTimeImmutable('2026-02-01 23:59:59')
);
```

## Lessons Learned

- **Mixpanel Export API returns soft-deleted events** — cannot be used as source of truth for "what's visible in UI"
- **Soft delete → Hard delete: 7 days** — during this window, re-imports with same `$insert_id` fail silently
- **Mixpanel recommends regenerating `$insert_id`** for ETL re-imports to avoid dedup collisions
- **Consider**: If this becomes a recurring issue, we could add a timestamp suffix to `$insert_id` to force fresh imports

## References

- [Mixpanel Data Clean-Up Docs](https://docs.mixpanel.com/docs/data-governance/data-clean-up)
- [Mixpanel GDPR Compliance](https://docs.mixpanel.com/docs/privacy/gdpr-compliance)
- CVE-2026-25129: PsySH Restricted Mode (unrelated but discovered during session)