# Code Review: #437 Catalog Postgres Views & Query Infrastructure

**Date:** 2026-03-31
**Branch:** feature/437-catalog-views-query-infrastructure
**Base:** develop
**Files reviewed:** 31 (22 modified + 9 new)

## Findings

### CRITICAL
None.

### HIGH
None.

### MEDIUM

- [ProductSortField.php:10] `Price` sort field maps to base `price` column, not `effective_price`. Users sorting by "price" get RRP ordering, not sale-adjusted. **Status: Fixed** — added `EffectivePrice` case mapped to `effective_price` column.

- [ProductSortField.php:19-26] `column()` method on domain enum contains DB column name knowledge (infrastructure concern). **Status: Fixed** — extracted to `ProductSortFieldMapper` in Infrastructure, removed `column()` from enum.

- [2026_03_31_110001:27,113] Hardcoded VAT rate `0.20` in SQL views (`tax_config` CTE). Synced to PHP `TaxRate::standard()` by comment only. **Status: Accepted** — UK VAT stable since 2011; comment is sufficient.

### LOW

- [ProductViewAssembler.php:189-208] `FreeDeliveryType` addition not in issue #437 scope. **Status: Accepted** — assembler was being reworked, pragmatic to include.

- [ShopwiredModelMustImplementMappableRule.php:53] ViewModel bypass uses broad suffix match `\str_ends_with($className, 'ViewModel')`. Unlikely to cause false positives given naming conventions. **Status: Skipped** — low risk.

## Positive Observations

- **Clean decomposition**: CTE pipeline in SQL views mirrors the PHP logic exactly. The `tax_config → pricing → main SELECT` structure is well-documented and maintainable.
- **Correct removal of ProductCostPriceFactory**: All references cleaned up across assembler, mapper, service provider, and tests. The view-based approach is both simpler and more performant.
- **Good test hygiene**: PHP-computed tests (profitMargin, isOnSale) correctly removed since computation moved to SQL. Static utility method tests preserved.

## Summary

Well-structured feature that cleanly migrates computed read-path data from PHP to PostgreSQL views. Two actionable findings addressed: added `EffectivePrice` sort field and extracted DB column mapping to a dedicated infrastructure mapper. No security, performance, or correctness issues found.
