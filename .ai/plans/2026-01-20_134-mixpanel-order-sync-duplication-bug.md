# Mixpanel Order Sync Duplication Bug - Investigation & Fix Plan

## Problem Summary

The `SyncOrdersToMixpanelJob` is creating duplicate "Checkout Completed" events. The backend-synced events should be deduplicated against frontend-tracked events via `order_id_hashed`, but the hashes differ:

- **Frontend**: `06a36d5e1f3636f532b8e24d73dc0c53648ceeb2a99a51f7af9a2354d0fb5132` (source: `website`)
- **Backend**: `3eaccd370e35d41b0d3987b93dcb3d67eac0e5667b82b6d78ff0d793e97171b8` (source: `backend-sync`)

**Impact**: ~5 days of corrupted data (Jan 15-20, 2026). Example: 22 orders → 39 Mixpanel events.

## Verified Facts

- ✅ Both use SHA-256 (hashes are 64 hex chars) — Legacy Base64 fallback ruled out
- ✅ Salt is configured and matches (per user confirmation)
- ✅ Reference format is plain integer (per user's extensive ShopWired experience)
- ✅ Type coercion (int vs string) produces identical hashes (empirically tested)
- ✅ Backend-sync is source of truth (captures ALL orders inc. privacy/offline)
- ❓ **Something else** must differ in the actual hash input — needs byte-level investigation

## Key Insight

The backend sync is MORE reliable than frontend tracking because it captures:
- Privacy-focused customers (ad blockers, no JS)
- Credit/offline orders
- Orders where frontend JS failed

Therefore: Delete FRONTEND events, keep backend-sync, then re-run sync.

---

## Phase 1: Immediate Production Fix

### Task 1.1: Comment Out Scheduler (URGENT)

**File**: `routes/console.php`

Comment out both scheduler entries (lines ~202-228):

```php
// TEMPORARILY DISABLED: Duplicate events bug - see Issue #XXX
// Schedule::call(static function (): void {
//     SyncOrdersToMixpanelJob::dispatch(
//         from: new DateTimeImmutable('-28 hours'),
//         to: new DateTimeImmutable('now'),
//     );
// })
//     ->name('sync-orders-to-mixpanel-nightly')
//     ...

// Schedule::call(static function (): void {
//     SyncOrdersToMixpanelJob::dispatch(
//         from: new DateTimeImmutable('-14 days'),
//         to: new DateTimeImmutable('now'),
//     );
// })
//     ->name('sync-orders-to-mixpanel-weekly')
//     ...
```

**Commit**: `fix(mixpanel): disable order sync scheduler - duplicate events bug`
**Deploy**: Push to production immediately

### Task 1.2: Check for Pending Queued Jobs

The job uses the `low` queue. Check and clear any pending jobs:

```bash
# Check pending jobs on low queue
php artisan horizon:queue-status

# Or via Redis directly
redis-cli LLEN queues:low

# If jobs are pending, either wait for them to complete or clear:
php artisan queue:clear low
```

---

## Phase 2: Root Cause Investigation

### Task 2.0: Verify Order Reference Format on Live Checkout

Before adding debug logging, confirm what ShopWired actually outputs:

1. Place a test order or view source on a recent checkout complete page
2. Find `<div id="checkout-order-data" data-order-reference="..."`
3. Confirm format is plain integer (e.g., `12345`) not prefixed (e.g., `SW12345`)

**Expected**: Plain integer based on user's extensive ShopWired experience.

### Task 2.1: Capture Frontend Hash Input (CRITICAL)

Deploy temporary debug logging to `shopwired-theme` to capture real order data.

**File**: `assets/js/utils/data/checkoutPageData.js` (in `transformOrderData` function)

```javascript
// TEMPORARY DEBUG - Remove after investigation
const debugData = {
  // Raw values from DOM
  order_reference: orderData.order_reference,
  order_reference_type: typeof orderData.order_reference,
  order_reference_length: String(orderData.order_reference).length,

  analytics_salt: orderData.analytics_salt,
  analytics_salt_length: orderData.analytics_salt.length,

  // Combined input (what gets hashed)
  combined_input: orderData.order_reference + orderData.analytics_salt,
  combined_length: (orderData.order_reference + orderData.analytics_salt).length,

  // Byte-level inspection
  combined_bytes: [...new TextEncoder().encode(orderData.order_reference + orderData.analytics_salt)],
  salt_bytes: [...new TextEncoder().encode(orderData.analytics_salt)],

  // Resulting hash
  order_id_hashed: hashedOrderId,
};

// Log to console AND send to Sentry for production visibility
console.log('[MIXPANEL DEBUG] Hash input:', JSON.stringify(debugData, null, 2));

// Optional: Send to Sentry as breadcrumb for production debugging
if (window.Sentry) {
  Sentry.addBreadcrumb({
    category: 'mixpanel-debug',
    message: 'Hash input captured',
    data: debugData,
    level: 'info',
  });
}
```

**Data Required from Frontend** (checklist for comparison):

| Field | Purpose | Example |
|-------|---------|---------|
| `order_reference` | Raw value from DOM | `"12345"` |
| `order_reference_type` | JS type | `"string"` |
| `order_reference_length` | Character count | `5` |
| `analytics_salt` | Salt value | `"my-secret-salt"` |
| `analytics_salt_length` | Salt char count | `14` |
| `combined_input` | What gets hashed | `"12345my-secret-salt"` |
| `combined_length` | Total char count | `19` |
| `combined_bytes` | UTF-8 byte array | `[49,50,51,52,53,109,121,...]` |
| `salt_bytes` | Salt as bytes | `[109,121,45,115,101,...]` |
| `order_id_hashed` | Resulting hash | `"abc123..."` |

**Deployment**: Requires push to `shopwired-theme` repo and ShopWired theme publish.

### Task 2.2: Capture Backend Hash Input

Add temporary logging in PHP to capture matching data fields.

**File**: `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php`

```php
// TEMPORARY DEBUG - Remove after investigation
// Add inside the loop that processes orders, before/after hash generation
$combined = $order->reference . $this->analyticsSalt;

Log::info('[MIXPANEL DEBUG] Hash input', [
    // Raw values
    'order_reference' => $order->reference,
    'order_reference_type' => gettype($order->reference),
    'order_reference_length' => strlen((string) $order->reference),

    'analytics_salt' => $this->analyticsSalt,
    'analytics_salt_length' => strlen($this->analyticsSalt),

    // Combined input (what gets hashed)
    'combined_input' => $combined,
    'combined_length' => strlen($combined),

    // Byte-level inspection (matches frontend format)
    'combined_bytes' => array_values(unpack('C*', $combined)),
    'salt_bytes' => array_values(unpack('C*', $this->analyticsSalt)),

    // Resulting hash
    'order_id_hashed' => hash('sha256', $combined),
]);
```

**Data Required from Backend** (checklist for comparison):

| Field | Purpose | Example |
|-------|---------|---------|
| `order_reference` | Value from Order object | `12345` (int) |
| `order_reference_type` | PHP type | `"integer"` |
| `order_reference_length` | Character count | `5` |
| `analytics_salt` | Salt from config | `"my-secret-salt"` |
| `analytics_salt_length` | Salt char count | `14` |
| `combined_input` | What gets hashed | `"12345my-secret-salt"` |
| `combined_length` | Total char count | `19` |
| `combined_bytes` | Byte array | `[49,50,51,52,53,109,121,...]` |
| `salt_bytes` | Salt as bytes | `[109,121,45,115,101,...]` |
| `order_id_hashed` | Resulting hash | `"abc123..."` |

**Note**: Using `Log::info` (not `Log::debug`) to ensure it appears in production logs.

### Task 2.3: Compare Frontend vs Backend (Side-by-Side)

For the **same order reference**, fill in this comparison table:

| Field | Frontend Value | Backend Value | Match? |
|-------|---------------|---------------|--------|
| `order_reference` | | | ☐ |
| `order_reference_type` | | | ☐ |
| `order_reference_length` | | | ☐ |
| `analytics_salt` | | | ☐ |
| `analytics_salt_length` | | | ☐ |
| `combined_input` | | | ☐ |
| `combined_length` | | | ☐ |
| `combined_bytes` | | | ☐ |
| `salt_bytes` | | | ☐ |
| `order_id_hashed` | | | ☐ |

**How to populate this table:**

1. **Frontend data**: From Sentry breadcrumb or browser console on checkout complete page
2. **Backend data**: From Laravel logs after running sync job, OR from tinker (Task 2.4)
3. **Use same order reference** for both

**First mismatch = root cause.** Work down the table until you find the first field that differs.

### Task 2.4: Manual Hash Verification via Tinker

Generate backend hash for a specific order to compare with Mixpanel:

```bash
php artisan tinker --execute="
\$salt = config('mixpanel.analytics_salt');
\$reference = 12345; // Replace with actual order reference

echo 'Salt: ' . \$salt . PHP_EOL;
echo 'Salt length: ' . strlen(\$salt) . PHP_EOL;
echo 'Salt bytes: ' . json_encode(array_map('ord', str_split(\$salt))) . PHP_EOL;
echo 'Reference: ' . \$reference . PHP_EOL;
echo 'Combined: ' . \$reference . \$salt . PHP_EOL;
echo 'Hash: ' . hash('sha256', \$reference . \$salt) . PHP_EOL;
"
```

### Likely Culprits to Check

1. **Salt whitespace**: Trailing/leading spaces in config
2. **Salt encoding**: BOM or invisible Unicode chars
3. **Reference mismatch**: Frontend sees different value than database
4. **DOM encoding**: HTML entity encoding in data attributes

---

## Phase 3: Fix Implementation

### Option A: Fix Configuration Mismatch (if found)

If salt/reference values differ, correct the configuration and re-verify hashes match.

### Option B: Add Legacy Hash Support (FUTURE IMPROVEMENT - NOT FOR THIS BUG)

> **Note**: Both example hashes are SHA-256 format (64 hex chars). Legacy Base64 support won't fix THIS bug.
> This is a separate resilience improvement for browsers without `crypto.subtle`.
> The current `OrderAnalyticsHash` requires 64 chars - legacy hashes are 32 chars.
> Would require either a separate value object or modified validation.

**Defer to separate issue/PR.**

---

## Phase 4: Data Cleanup

**IMPORTANT**: Backend-sync is the source of truth (captures ALL orders including privacy-focused customers, credit/offline orders). Frontend tracking is best-effort and misses orders.

### Task 4.1: Delete FRONTEND Events in Mixpanel

1. Navigate to: **Project Settings → Data Deletion**
2. Click "Request an Event Deletion"
3. Configure:
   - **Event**: "Checkout Completed"
   - **Time range**: Jan 15, 2026 - Jan 20, 2026
   - **Filter**: `source = "website"` (DELETE FRONTEND, keep backend)
4. Validate preview shows correct events
5. Submit deletion request

**Note**: Deletion has 7-day grace period. Can be undone within that window.

### Task 4.2: Also Delete Frontend Product Events

Repeat for "Product Purchased" events with filter `source = "website"`.

### Task 4.3: Re-run Backend Sync for Affected Period

After deletion completes (or during 7-day grace period when data is hidden):

```bash
# Dispatch job for the affected time range
php artisan tinker --execute="
App\Presentation\Jobs\SyncOrdersToMixpanelJob::dispatch(
    from: new DateTimeImmutable('2026-01-15 00:00:00'),
    to: new DateTimeImmutable('2026-01-20 23:59:59'),
);
"
```

This will upload ALL orders from that period with zero deduplication (since frontend events are gone).

---

## Phase 5: Re-enable with Verification

### Task 5.1: Verify Fix with Single Order

Before re-enabling scheduler:
1. Pick a recent order tracked by frontend
2. Generate backend hash for same order
3. Confirm hashes match

### Task 5.2: Re-enable Scheduler

Uncomment the scheduler entries in `routes/console.php`.

### Task 5.3: Monitor First Run

Watch logs for the first nightly run:
- Should skip all orders (already tracked by frontend)
- `synced: 0` in completion log

---

## Critical Files

| File | Purpose |
|------|---------|
| `routes/console.php:202-228` | Scheduler entries to comment out |
| `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php:192` | Hash generation/comparison |
| `app/Domain/Catalog/Order/ValueObjects/OrderAnalyticsHash.php:34-40` | Hash algorithm |
| `shopwired-theme/assets/js/utils/data/checkoutPageData.js:113-122` | Frontend hash generation |
| `shopwired-theme/assets/js/utils/crypto.js:11-37` | Frontend SHA-256 + fallback |
| `shopwired-theme/src/views/platform_checkout.twig:15-16` | Salt configuration |

---

## Verification Checklist

### Phase 1: Stop the Bleeding
- [ ] Scheduler commented out in `routes/console.php`
- [ ] Changes deployed to production
- [ ] Pending jobs on `low` queue checked/cleared

### Phase 2: Investigation
- [ ] Order reference format verified on live checkout page
- [ ] Frontend debug logging deployed to `shopwired-theme`
- [ ] Backend debug logging added to `SyncOrdersToMixpanelUseCase`
- [ ] Test order placed to capture frontend data
- [ ] Comparison table filled in for same order (Task 2.3)
- [ ] **Root cause identified** (first mismatched field found)

### Phase 3: Fix
- [ ] Fix implemented based on root cause
- [ ] Hash generation verified to match between frontend and backend

### Phase 4: Data Cleanup
- [ ] Frontend events deleted for Jan 15-20 (`source = "website"`)
- [ ] Product Purchased frontend events also deleted
- [ ] Backend sync re-run for affected period
- [ ] Event counts verified (should match actual order count)

### Phase 5: Re-enable
- [ ] Debug logging removed from both codebases
- [ ] Scheduler re-enabled
- [ ] First nightly run monitored
- [ ] Deduplication confirmed working (synced: 0 for already-tracked orders)

---

## Timeline

| Date | Status |
|------|--------|
| Jan 15, 2026 | Feature went live |
| Jan 20, 2026 | Bug discovered |
| Jan 20, 2026 | Scheduler disabled (Phase 1) |
| TBD | Root cause identified (Phase 2) |
| TBD | Fix deployed (Phase 3) |
| TBD | Data cleaned (Phase 4) |
| TBD | Re-enabled (Phase 5) |
