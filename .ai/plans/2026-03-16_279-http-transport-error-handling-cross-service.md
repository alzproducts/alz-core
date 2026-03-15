# HTTP Transport Error Handling — Cross-Service Fixes

## Context

Sentry issue ALZ-CORE-46 revealed a 422 response being misclassified as `ExternalServiceUnavailableException` in ShopWired's pool handling. The **ShopWired fixes have already been applied** (added `instanceof RequestException` check in `handlePoolResult()` + added `422` to the match block). However, a cross-transport audit revealed the **same issues exist in other transports** and minor logging improvements remain for ShopWired.

## Cross-Transport Audit

| Issue | SW | HS | MP | LW | RIO |
|-------|----|----|----|----|-----|
| Pool missing `instanceof RequestException` | ✅ Fixed | **LATENT** | — | — | — |
| 422 not handled (falls to server error) | ✅ Fixed | **YES** | **YES** | **YES** | **YES** |
| Hardcoded `'status' => 400` in log | **YES** | **YES** | **YES** | **YES** | **YES** |
| Pool catch-all missing `exception_class` | **YES** | **YES** | — | — | — |
| Missing endpoint in `handleBadRequest` | **YES** | N/A | — | — | — |

*SW=ShopWired, HS=HelpScout, MP=Mixpanel, LW=Linnworks, RIO=ReviewsIo. "—" = no pool / already has it*

**Key files:**
- `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`
- `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php`
- `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php`
- `app/Infrastructure/Linnworks/LinnworksHttpTransport.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php`

---

## Implementation Plan

### Step 1: Fix HelpScout pool `instanceof RequestException` check

**File:** `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` — `handlePoolGetResult()` line 158-162

Add `instanceof RequestException` routing between the `ConnectionException` check and the catch-all. HelpScout's `handleRequestException()` doesn't take an endpoint param (pools only hit `/conversations`), so no endpoint context needed.

**Note:** This is a latent defect, not actively triggerable — HelpScout pool requests lack `.retry()` config, so HTTP errors return as `Response` objects (handled correctly by the `$result->failed()` path). The fix prevents the bug from activating if retry is added later (exactly what happened with ShopWired).

### Step 2: Add 422 handling to remaining 4 transports

**Files:**
- `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` — `handleRequestException()`: `400 =>` → `400, 422 =>`
- `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php` — `handleRequestException()`: `400 =>` → `400, 422 =>`
- `app/Infrastructure/Linnworks/LinnworksHttpTransport.php` — `handleRequestException()`: `400 =>` → `400, 422 =>`
- `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php` — `handleRequestException()`: `400 =>` → `400, 422 =>`

### Step 3: Fix hardcoded `'status' => 400` in all 5 transports

Change `'status' => 400` to `'status' => $e->response->status()` in each `handleBadRequest()`:
- `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`
- `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php`
- `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php`
- `app/Infrastructure/Linnworks/LinnworksHttpTransport.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php`

### Step 4: Add `exception_class` to pool catch-all logging

**Files:**
- `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` — pool catch-all `Log::error()` context
- `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` — pool catch-all `Log::error()` context

Add `'exception_class' => $result::class` to the existing `Log::error()` context.

### Step 5: Add endpoint context to ShopWired `handleBadRequest()`

**File:** `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`

Update `handleBadRequest()` to accept `string $endpoint`, add to log context. Update call site in `handleRequestException()` to pass through.

### Step 6: Add test coverage

**File:** `tests/Feature/Infrastructure/Api/ShopwiredClientTest.php`

1. **`it_throws_invalid_request_on_http_422`** — Explicit 422 → `InvalidApiRequestException`
2. **`it_logs_actual_status_code_for_422`** — Verify log contains `'status' => 422`

Pool-specific tests — test `ShopwiredHttpTransport::poolPost()` directly with `Http::fake()`:
3. **Pool 422 → `InvalidApiRequestException`**
4. **Pool 500 → `ExternalServiceUnavailableException`**
5. **Pool connection failure → `ExternalServiceUnavailableException`**

---

## Files to Modify

| File | Changes |
|------|---------|
| `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` | Steps 3, 4, 5 |
| `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` | Steps 1, 2, 3, 4 |
| `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php` | Steps 2, 3 |
| `app/Infrastructure/Linnworks/LinnworksHttpTransport.php` | Steps 2, 3 |
| `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php` | Steps 2, 3 |
| `tests/Feature/Infrastructure/Api/ShopwiredClientTest.php` | Step 6 |

## Verification

```bash
make test          # All tests pass
make lint          # All linters pass
```

Post-deploy: monitor Sentry ALZ-CORE-46 — should not recur. Reference `Fixes ALZ-CORE-46` in commit.
