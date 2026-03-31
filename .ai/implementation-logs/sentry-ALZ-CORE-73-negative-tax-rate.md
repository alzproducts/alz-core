# Implementation Log: #446 — fix(linnworks): Handle negative tax rate sentinel from Linnworks PO sync (ALZ-CORE-73)

**GitHub Issue**: #446
**Status**: In Progress
**Started**: 2026-04-01

## Overview

Linnworks API returns negative `TaxRate` values (e.g. `-1`) as a sentinel meaning "not set". The PO sync
code passed these raw to `TaxRate::fromPercentage()`, which rejects negatives via assertion, causing
`SyncAllPurchaseOrdersJob` to fail permanently (3 occurrences on 2026-03-31, production only).

Existing precedent in `StockItemFullResponse.php`: `$this->taxRate < 0 ? null : $this->taxRate`.

## Decision Log

### 2026-04-01
- **Decision**: Map negative tax rates to `null` (not `0.0`)
- **Why**: Negative = "not set", while `0.0` = "zero-rated" — distinct business concepts
- **Tradeoff**: Domain VOs now have nullable taxRate; all consumers must handle null

### 2026-04-01
- **Decision**: Fix all 5 Infrastructure call sites + 3 Domain VOs + 3 DB columns
- **Why**: Completeness — header's shippingTaxRate and additional cost taxRate can also be sent as -1
- **Tradeoff**: More blast radius, but prevents same bug resurfacing on other PO sub-objects

## Implementation

### Sub-task 1: Domain — make taxRate nullable
- `app/Domain/Linnworks/ValueObjects/PurchaseOrderItem.php` — `TaxRate $taxRate` → `?TaxRate $taxRate`
- `app/Domain/Linnworks/ValueObjects/PurchaseOrderHeader.php` — `TaxRate $shippingTaxRate` → `?TaxRate $shippingTaxRate`
- `app/Domain/Linnworks/ValueObjects/PurchaseOrderAdditionalCost.php` — `TaxRate $taxRate` → `?TaxRate $taxRate`

### Sub-task 2: Database migration — make columns nullable
- `database/migrations/2026_04_01_100000_make_linnworks_purchase_order_tax_rates_nullable.php`
- Columns: `linnworks.purchase_order_items.tax_rate`, `linnworks.purchase_orders.shipping_tax_rate`, `linnworks.purchase_order_additional_costs.tax_rate`

### Sub-task 3: Infrastructure — map negative → null (5 call sites)
Pattern: `$this->taxRate < 0 ? null : TaxRate::fromPercentage($this->taxRate)`
- `PurchaseOrderItemResponse.php` — `toDomain()` taxRate
- `PurchaseOrderHeaderResponse.php` — `toDomain()` shippingTaxRate
- `PurchaseOrderAdditionalCostResponse.php` — `toDomain()` taxRate
- `PurchaseOrderItemsBatchQuery.php` — `mapResponse()` TaxRate line
- `PurchaseOrderHeadersBatchQuery.php` — `toDomain()` ShippingTaxRate line

### Sub-task 4: Model mappers — handle nullable `->percentage`
- `PurchaseOrderItemModel.php` — `$item->taxRate->percentage` → `$item->taxRate?->percentage`
- `PurchaseOrderAdditionalCostModel.php` — `$cost->taxRate->percentage` → `$cost->taxRate?->percentage`
- `EloquentPurchaseOrderSyncRepository.php` — `$header->shippingTaxRate->percentage` → `$header->shippingTaxRate?->percentage`

### Sub-task 5: Bonus — improve logging in SyncPurchaseOrderFullUseCase
- Added `$this->logger->debug('Fetching purchase order full', ['purchase_id' => $id->value])` BEFORE `getPurchaseOrderFull()` call

## Test Results

- `make test-quick` (Domain suite): **1386 passed**, 0 failures

## Lint Results

- Pint: pass
- PHPStan: No errors (fixed 2 errors: `PurchaseOrderHeaderUpdateDTO` needed `?TaxRate`, then ternary to avoid `nullsafe.neverNull`)
- PHPArkitect: No violations
- Deptrac: No violations
- TLint: LGTM

## PR Notes

### What
Map Linnworks negative tax rate sentinels (`-1`) to `null` across all PO sub-objects (items, header shipping, additional costs).

### Why
Linnworks uses `-1` as a "not set" sentinel — identical to existing stock item handling. `TaxRate::fromPercentage()` correctly rejects negatives, so Infrastructure must translate before reaching Domain.

### Key Decisions
- `null` not `0.0` — zero-rated and not-set are distinct
- Fixed all 5 Infrastructure call sites + 3 Domain VOs + 3 DB column migrations to prevent the same issue on other PO sub-objects
- Added pre-fetch debug log so failing POs are identifiable in Sentry

### Testing
Existing test suite; no new tests needed (sentinel mapping is a trivial guard following established pattern).
