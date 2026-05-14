# COR-129 — Fix Best Sellers label sync: send string not array

## Status: Implementation complete, awaiting lint/test

## Root Cause

`custom_label_4` is a single-select string field in ShopWired (not `value_list`/array type).
The original code sent `["Best Sellers"]` (array). ShopWired silently ignores array values for
string-type fields — the PUT accepted it but the field was never updated.

Fix confirmed by sending `"Best Sellers"` (plain string) → 200 OK → field updated in admin.

## Changes

### Array → string throughout the label pipeline

| File | Change |
|------|--------|
| `BestSellerLabelTransformer` | Removed `addLabel()`/`removeLabel()` array methods; constants only |
| `ProductLabelCandidateDTO` | Removed `currentLabels: list<string>` property; `productId` only |
| `SyncBestSellerLabelUseCase` | `dispatchBestSellerLabelUpdate($id, LABEL)` / `(id, null)` directly |
| `ShopwiredSyncDispatcherInterface` | `?array $targetLabels` → `?string $label` |
| `QueuedShopwiredSyncDispatcher` | Same signature update |
| `SetProductBestSellerLabelJob` | `?array $targetLabels` → `?string $label` |
| `SetProductBestSellerLabelUseCase` | `?array $targetLabels` → `?string $label`; removed `@param` docblock |
| `ProductViewQueryRepository` | JSONB `->` + `@> ?::jsonb` → `->>`+ `= ?` / `!= ?`; `mapToCandidates` no longer reads `custom_fields` |

### Key SQL change

Old: `custom_fields->'custom_label_4' @> '["Best Sellers"]'::jsonb`
New: `custom_fields->>'custom_label_4' = 'Best Sellers'`

`->>` extracts text (without JSON quotes), enabling plain string binding.
`->` returns jsonb — incompatible with `= ?` string bindings.

## Null / clear behaviour

`MergesCustomFieldsTrait` converts `null` → `''` before the ShopWired PUT.
Sending `null` to the use case correctly clears the field via this existing mechanism.

## No tests

No unit tests exist for this feature (was added in #750). No new tests added in this fix.

## Post-review cleanups (from /check)

- Renamed private constant `LABEL_JSONB_PATH` → `LABEL_TEXT_PATH` in `ProductViewQueryRepository` (path now uses `->>` text extraction, not `->` JSONB).
- Converted `BestSellerLabelTransformer` (class) → `BestSellerLabel` (string-backed enum) and moved to `App\Application\Catalog\Enums\BestSellerLabel`. Single case `BestSellers = 'Best Sellers'`; `FIELD = 'custom_label_4'` lives as a class constant on the enum. Added `App\Application\Catalog\Enums` to the `phparkitect.php` Application-suffix-rule exclusion list (matches existing Inventory/Linnworks/Shopwired/HelpScout pattern).
- Deleted `ProductLabelCandidateDTO` (single-field IntId wrapper); `BestSellerLabelChangesResult` now exposes `list<IntId>` directly.
- Stale references to old names remain only in COR-128 historical docs (frozen-in-time, not edited).
