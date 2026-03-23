# Code Review: Sale State Reconciliation Refactoring

**Date:** 2026-03-24
**Branch:** `feature/346-port-price-update-side-effects`
**Scope:** 27 files changed — 815 insertions, 613 deletions
**Reviewers:** Claude Opus 4.6 (internal) + Gemini 2.5 Flash (external)
**Scope note:** This is a small business application. Recommendations prioritise proportional value.

## Executive Summary

Major architectural refactoring replacing event-driven sale state management (SaleStateDetectionService → 4 domain events → 4 listeners) with a reconciliation-based approach (ProductSaleStateResolver + ReconcileProductSaleState/BulkSaleState UseCases). The refactoring is **well-executed** with clean CA compliance, comprehensive tests, and smart performance optimisations.

**1 HIGH** finding requires attention before merge (queue timeout mismatch). **3 MEDIUM** findings are worth addressing but not blocking. **2 LOW** findings are informational.

**Overall assessment:** Ship with the HIGH fix applied. MEDIUM items can be addressed in this PR or as follow-ups.

## Findings by Severity

### HIGH (1)

#### H1. Queue Timeout Mismatch — ReconcileBulkSaleStateJob

| | |
|---|---|
| **Location** | `app/Infrastructure/Jobs/Shopwired/ReconcileBulkSaleStateJob.php:34` |
| **Source** | Both (Internal + External) |
| **Consensus** | Agreed — High confidence |

`ReconcileBulkSaleStateJob` declares `$timeout = 120` and dispatches to `QueueName::Default`. In `config/horizon.php`, the production `supervisor-default` has `timeout: 90`. Horizon kills the worker process at 90s — before the job's own 120s timeout fires. With `$failOnTimeout = true`, the job silently fails.

**Impact:** Bulk reconciliation will be killed if the catalog scan takes >90s. Currently may not manifest with a small catalog, but will silently break as the catalog grows.

**Recommendation (pick one):**
- **(a)** Move to `QueueName::Low` (supervisor timeout = 9300s) — best fit for a scheduled background scan
- **(b)** Reduce `$timeout` to `85` (5s buffer under supervisor's 90s)

---

### MEDIUM (3)

#### M1. Asymmetric Drift Detection — SQL vs Resolver

| | |
|---|---|
| **Location** | `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php:621` |
| **Source** | Both (Internal: MEDIUM, External: HIGH) |
| **Consensus** | Agreed on existence; severity disputed |

The SQL `buildSaleStateDriftQuery` Case 2 (not on sale, needs remove) only checks `category_ids`. But `ProductSaleStateResolver::needsRemoveFromSale` also checks `hasSaleCustomFields`. Products NOT in the sale category but with orphaned custom fields won't be caught by bulk reconciliation.

The code comment documents this as intentional: *"sale custom fields sometimes get left behind and should not trigger reconciliation alone."*

**Impact:** Orphaned custom fields persist indefinitely on products that never receive another price update AND aren't in the sale category. These fields are not customer-facing — they're internal metadata.

**Recommendation:** Acceptable trade-off for now (YAGNI). Per-update reconciliation handles cleanup when the product gets its next price change. If orphaned custom fields cause issues later, add the custom field check to Case 2. No action needed now.

#### M2. Broad `catch(Throwable)` in Best-Effort DB Sync

| | |
|---|---|
| **Location** | `app/Application/Shopwired/PricingUpdate/UseCases/UpdateProductPricesUseCase.php:107` |
| **Source** | Both |
| **Consensus** | Agreed — High confidence |

The best-effort DB sync catches `Throwable`, which includes `Error` subclasses (`OutOfMemoryError`, `TypeError`). While the `@ignoreException` annotation shows deliberate intent, catching `Error` can mask serious bugs.

**Recommendation:** Narrow to `\Exception` — still protects event dispatch from all runtime exceptions while letting fatal errors propagate:
```php
} catch (\Exception $e) { // @ignoreException — best-effort sync must not block events
```

#### M3. Missing `saleStartDate` in `buildSaleSettingsFromProduct`

| | |
|---|---|
| **Location** | `app/Application/Shopwired/SaleManagement/UseCases/ReconcileProductSaleStateUseCase.php:92` |
| **Source** | Both |
| **Consensus** | Agreed — High confidence |

`buildSaleSettingsFromProduct()` reconstructs `SaleSettings` from custom fields but omits `SaleCustomField::DateStart`. If the downstream add-to-sale job uses `saleStartDate` to write the custom field, reconciliation-rebuilt `SaleSettings` would overwrite the existing start date with `null`.

**Recommendation:** Add `DateStart` reconstruction mirroring the `DateEnd` pattern:
```php
$dateStart = $raw[SaleCustomField::DateStart->value] ?? null;
$startDate = null;
if (\is_string($dateStart) && $dateStart !== '') {
    try {
        $startDate = new DateTimeImmutable($dateStart);
    } catch (DateMalformedStringException) {
        // Intentionally left empty
    }
}
// Then pass saleStartDate: $startDate to SaleSettings constructor
```

---

### LOW (2)

#### L1. Direct `rawCustomFields` Access in Resolver

| | |
|---|---|
| **Location** | `app/Application/Shopwired/SaleManagement/Resolvers/ProductSaleStateResolver.php:48` |
| **Source** | Internal only |
| **Confidence** | Medium |

The `Product` docblock says *"Do NOT read from this directly — use getCustomField()"*. However, the resolver checks field *presence* (not typed value), which legitimately requires raw access. The pattern is correct but inconsistent with documented practice.

**Recommendation:** No action needed — raw access is appropriate here. Consider adding a brief comment explaining why.

#### L2. No Test for Malformed Date Handling

| | |
|---|---|
| **Location** | `app/Application/Shopwired/SaleManagement/UseCases/ReconcileProductSaleStateUseCase.php:108` |
| **Source** | Internal only |
| **Confidence** | Low |

`buildSaleSettingsFromProduct` catches `DateMalformedStringException` and treats malformed dates as absent. No test explicitly verifies this edge case.

**Recommendation:** Add a test with `'sale_date_end' => 'not-a-date'` to verify graceful degradation. Low priority.

---

## Positive Findings

1. **Clean Architecture compliance** — `SaleReconciliationDispatcherInterface` in Application/Contracts, `QueuedSaleReconciliationDispatcher` in Infrastructure/Dispatchers. Layer boundaries are respected throughout.
2. **Pure resolver pattern** — `ProductSaleStateResolver` has zero framework dependencies, making it trivially testable and highly reliable.
3. **Smart fast-path optimisation** — `hasSaleStateDrift()` runs a lightweight EXISTS query before loading the full Product VO, avoiding unnecessary DB reads.
4. **Proper idempotency** — `ShouldBeUnique` with `uniqueFor` on both jobs prevents redundant reconciliation work and job flooding.
5. **HandleDatabaseExceptions middleware** — centralises DB-only job error handling, reducing boilerplate across jobs.
6. **Comprehensive test coverage** — 4 test files with 23+ test cases covering resolver logic, use case fast-paths, dispatch verification, SaleSettings reconstruction, and sync failure resilience.
7. **Clean event removal** — all 4 domain events, their listeners, and EventServiceProvider registrations removed cleanly with no orphaned references.

## Decision Audit Trail

| Finding | Internal | External | Resolution |
|---------|----------|----------|------------|
| Queue timeout | HIGH | CRITICAL/HIGH | **HIGH** — confirmed bug, simple fix |
| Asymmetric drift | MEDIUM | HIGH | **MEDIUM** — documented trade-off, YAGNI |
| catch(Throwable) | MEDIUM | MEDIUM | **MEDIUM** — agreed, one-line fix |
| Missing saleStartDate | MEDIUM | MEDIUM | **MEDIUM** — agreed, simple addition |
| rawCustomFields access | LOW | not found | **LOW** — correct pattern, minor inconsistency |
| No malformed date test | LOW | not found | **LOW** — edge case test gap |

## Limitations

- **External consensus limited:** Gemini Pro models (3-pro-preview, 2.5-pro) were rate-limited. External analysis completed via Gemini 2.5 Flash only. Formal multi-model consensus was not run. Confidence levels are reduced accordingly for findings where independent validation would add value.
- **No integration test verification:** The SQL drift detection query was reviewed at the code level only. An integration test against a real database would provide higher confidence in the JSONB operator behaviour.
