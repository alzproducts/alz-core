# Fix: Deduplicate customer buffer before bulk upsert

## Problem

Rare SQLSTATE 21000 (cardinality violation) when bulk-upserting customers. Caused by pagination cursor drift during `created_desc` sorted sync — same `external_id` appears on two pages within the same 10-page buffer.

## Root Cause

The ShopWired API uses offset-based pagination with `created_desc` sorting. During the ~45 minute full sync of 68k customers, if a customer is created/modified mid-sync, records shift between pages. The same customer can appear on two consecutive pages, both landing in the same 10-page buffer (~1,000 customers). PostgreSQL's `INSERT ... ON CONFLICT` rejects the batch because two rows share the same `external_id`.

The per-row fallback in `EloquentGateway::batchUpsertMany()` handles this correctly, but the batch failure generates a noisy ERROR-level log.

## Change

**File**: `app/Application/Shopwired/UseCases/SyncCustomersUseCase.php`

In `flushBuffer()`, deduplicate the `$customers` array by `$customer->id` before passing to `saveCustomersBulk()`. Use **first-seen wins** (`??=`) because `created_desc` sorting means earlier entries have newer data.

```php
private function flushBuffer(array $customers, int|string $batchIdentifier): SyncResult
{
    // Deduplicate by customer ID (first-seen wins) — prevents cardinality violations
    // when pagination drift causes the same customer to appear on adjacent pages.
    // With created_desc sorting, earlier entries have newer data.
    $uniqueCustomers = [];
    foreach ($customers as $customer) {
        $uniqueCustomers[$customer->id] ??= $customer;
    }

    $deduplicatedCount = count($customers) - count($uniqueCustomers);
    if ($deduplicatedCount > 0) {
        $this->logger->debug('Deduplicated customers in batch', [
            'batch' => $batchIdentifier,
            'duplicates_removed' => $deduplicatedCount,
        ]);
    }

    $customers = array_values($uniqueCustomers);

    // ... existing flush logic unchanged
}
```

## Verification

- `make test` — all existing tests pass
- `make lint` — no linting issues
