# Money and VAT flow review for the QuickBooks order sync (COR-295)

Scope: ShopWired order ingest to the order read models the QBO sync reads (`shopwired.orders_deduplicated`, `shopwired.order_products_resolved`), the M1 line rules, a VAT replay of the as-built Zap against M1, and the float paths left for COR-308. Decision record: [ADR-0020](../adr/0020-decimal-money-value-object.md).

## 1. Float steps from ShopWired ingest to the sync-source read models

No step does arithmetic on money until the read side. The losses come from representation: decimal text becomes an IEEE 754 double, is stored at a fixed scale, and comes back as a double.

| # | Step | Where | Type change | Precision lost |
|---|------|-------|-------------|----------------|
| 1 | HTTP JSON decode | ShopWired clients parse `$response->json()` (`json_decode`) through `ShopwiredResponseParserTrait` | JSON decimal text → PHP `float` | The decimal is replaced by the nearest double. Up to 15 significant digits survive the round trip back to text; beyond that the digits change. Most binary fractions are inexact (`0.1` is not stored exactly), so later arithmetic drifts. |
| 2 | Spatie Data coercion | `OrderResponse.php:67-71` (`total`, `subTotal`, `shippingTotal`, `originalShippingTotal`, `partialPaymentTotal`); `OrderProductResponse.php:41-49` (`price`, `priceVat`, `total`, `totalVat`, `originalPrice`, `vatRate`; `costPrice` is `?float`); `OrderDiscountResponse.php:25` (`value`); `OrderRefundResponse.php:28` (`?float value`); `OrderShippingResponse.php:26-27` (`value`, `vatRate`); `OrderTaxResponse.php:25` (`?float value`, the source of `taxValue`) | decoded `int`/`float` → typed `float` property | An integer JSON value widens to a double without loss below 2^53. Nothing is added beyond step 1. |
| 3 | Domain value objects | `Order.php:65-82` (`total`, `subTotalNet`, `shippingTotalNet`, `originalShippingTotalNet`, `?taxValue`); `OrderProduct.php:32-39`; `OrderShipping.php:27-28` (`chargeNet`, `vatRate`); `OrderDiscount.php:19` and `OrderRefund.php:26` (`value`); `Order::totalDiscountValue` and `Order::totalRefundValue` (`Order.php:121-153`) | `float` held; two methods sum floats | Holding a value adds no loss. The two sums add rounding error per addition, and the error is not corrected before comparison or output. |
| 4 | Write to Postgres | `OrderModelMapper.php:149-153` writes the floats unchanged; `shipping_charge_net` falls back to `0.0` and `shipping_vat_rate` passes through nullable (`:193-194`) | `float` → bound string → `numeric(14,6)` money; `numeric(5,1)` for `order_products.vat_rate` and `orders.shipping_vat_rate` | PHP's float-to-string conversion is bounded by its `precision` setting (14 on this host). Whether the PDO bind uses it was not checked at runtime. `numeric(14,6)` holds at most 14 significant digits, so a value inside the column range survives. The column rounds anything past 6dp, and a rate past 1dp is rounded to 1dp. |
| 5 | Read casts | `OrderModel.php:113-119` (`total`, `sub_total_net`, `shipping_total_net`, `original_shipping_total_net`, `tax_value`, `shipping_charge_net`, `shipping_vat_rate` → `'float'`); `OrderProductModel.php:69-75` (`price`, `price_vat`, `total`, `total_vat`, `original_price`, `cost_price`, `vat_rate` → `'float'`); `OrderDiscountModel.php:56`, `OrderRefundModel.php:56` (`value` → `'float'`) | exact `numeric` → `float` | The exact stored decimal becomes the nearest double again, so every later computation in PHP starts inexact. |
| 6 | Read mapper fallbacks | `OrderModelMapper.php:251-252` | `NULL` → `0.0` | This is not a precision loss. When an order has a shipping method, a NULL `shipping_charge_net` and a NULL `shipping_vat_rate` both read back as `0.0`, so an unknown shipping rate becomes a 0% rate. An order with no shipping method gets no shipping object at all (`:244-246`). |

For the sync, the database values are the last exact form. The M1 sync path reads them as decimal strings into `DecimalMoney` and `VatMultiplier` (ADR-0020), never through steps 5 and 6.

## 2. M1 line rules

M1 keeps the Zap's line arithmetic (COR-295/D16), with two approved exceptions: the shipping source (COR-295/D8) and the shipping rounding (plan D10). Labels prefixed `COR-295/` are the issue's decisions; plan D10 is the implementation plan's decision.

- **Line shape (COR-295/D1, COR-295/D3).** Each line sends `UnitPrice` and `Qty`, with `GlobalTaxCalculation` set to `TaxExcluded`. QBO computes the VAT, and the sync sends no tax amounts.
- **VAT rate (COR-295/D16).** The rate is truncated to an integer. A rate below 1 divides by 1, so the gross price passes through as net.
- **Net unit price (COR-295/D16).** The net unit price is gross ÷ ((100 + rate) / 100), rounded to 7dp.
- **Line amount (COR-295/D16).** The line amount is that unit price × qty, rounded to 2dp.
- **Shipping line (COR-295/D8).** The shipping line's amount is `shipping_total_net`, the net shipping after discounts. The Zap posted the pre-discount figure, which overstated revenue on orders with discounted shipping.
- **Shipping rounding (plan D10).** The shipping line rounds to 2dp half away from zero, so .5 rounds up, as `DecimalMoney::round` does.

Points beyond Zap parity, such as a total guard, `vat_mismatch` warnings and VAT rounding changes, are held in COR-321 and not decided here.

## 3. VAT replay

### Method

`database/reports/vat-replay.sql` is one read-only `SELECT` that returns a single `jsonb` object of aggregates. It replays two variants (COR-295/D12) over the same cohort:

- **`m1`**: the Section 2 rules in exact `numeric`, with shipping from `shipping_total_net`.
- **`zap_as_built`**: the Zap's arithmetic in `double precision`. It uses the same truncated rate and divide-by-1 rule. Rounding adds a small nudge in the value's direction before a half-up floor, and a 2dp fallback check runs on each line. Shipping comes from `original_shipping_total_net`, rounded half away from zero (plan D10). When the fallback fires, the Zap changes the unit price and keeps the line amount, so the replay keeps the amount and counts the line.

QBO computes VAT from the line amounts, so each variant's VAT is replayed in two modes and compared with ShopWired's `tax_value`:

- **`per_line`**: each line's VAT is rounded to 2dp, then summed.
- **`per_rate`**: line amounts are summed per rate, and VAT is rounded once per rate.

Cohort and exclusions:

- **Cohort.** One order per reference (`orders_deduplicated`). Lines join on the winning order's `external_id`.
- **Refunds.** An order is dropped when any order with its reference has a refund.
- **Zero-line orders** are dropped.
- **Excluded orders** are counted per reason: `null_tax_value`, `null_shipping_rate`, `unmapped_rate` (a truncated rate outside {0, 20}), `ireland_remap` and `null_original_shipping` (the last applies to `zap_as_built` only).

The output holds only counts, signed pence deltas (replayed VAT − `tax_value`) and anonymised order shapes. A shape is made of a line-count bucket, a qty-above-1 flag, the truncated rates, a discounted-shipping flag, a zero-rate flag, `line_item_vat_calculation`, the baseline flag and, for `zap_as_built`, the fallback flag. `baseline` counts orders whose summed `m1` line amounts differ from `sub_total_net`.

The SQL's static test is `VatReplaySqlContentTest`. Its fixture is `database/reports/vat-replay-fixture.sql`, which runs on local Supabase inside a rolled-back transaction.

### Run command

A human runs this against prod with a read-only service; credentials come from `PGSERVICE` or `~/.pgpass`:

```bash
mkdir -p examples/vat-replay
PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120s' PGSERVICE=<prod read-only service> psql -X -v ON_ERROR_STOP=1 -v sql_sha256="$(shasum -a 256 database/reports/vat-replay.sql | cut -c1-64)" -At -f database/reports/vat-replay.sql > examples/vat-replay/vat-replay.json
jq -e -f database/reports/vat-replay-output-check.jq examples/vat-replay/vat-replay.json
```

Or paste a copy of the SQL, with `:'sql_sha256'` replaced by the file's sha256 literal, into the Supabase SQL editor for prod, and save the single result cell as `examples/vat-replay/vat-replay.json`. The editor copies the cell CSV-escaped (outer quotes, doubled inner quotes), so unwrap it before the `jq` check:

```bash
jq -R 'ltrimstr("\"") | rtrimstr("\"") | gsub("\"\""; "\"") | fromjson' examples/vat-replay/vat-replay.json > examples/vat-replay/vat-replay.unwrapped.json && mv examples/vat-replay/vat-replay.unwrapped.json examples/vat-replay/vat-replay.json
```

`/examples` is gitignored. No one reads the output until the `jq` check exits 0. Any later change to the SQL needs a re-run before merge.

### Results

Prod run on 2026-11-21 (COR-295 AC8), `sql_sha256` `2391d6b1803cbc719567be1eb588eb5492ea3763261eedc3cd8d4220f5404078`. The jq check exited 0 before anyone read the output.

- **Cohort:** 33,409 orders in each variant.
- **Excluded:** `null_tax_value` 9,389; `ireland_remap`, `unmapped_rate`, `null_shipping_rate` and `null_original_shipping` 0 each.
- **`baseline`:** 1,846 orders whose summed `m1` line amounts differ from `sub_total_net`.
- **Fallback (`zap_as_built`):** `fallback_lines` 147 on `fallback_orders` 129.

Mismatches against `tax_value` (Δ = replayed VAT − `tax_value`, in pence):

| Variant | VAT mode | Mismatched | Share | Δ > 0 | Δ < 0 | \|Δ\| ≤ 2 | Σ\|Δ\| | Net Δ | Max \|Δ\| |
|---------|----------|-----------:|------:|------:|------:|----------:|-------:|------:|----------:|
| `m1` | `per_line` | 1,052 | 3.1% | 326 | 726 | 747 | 3,151 | −1,295 | 48 |
| `m1` | `per_rate` | 5,698 | 17.1% | 562 | 5,136 | 5,324 | 8,222 | −5,936 | 50 |
| `zap_as_built` | `per_line` | 1,094 | 3.3% | 404 | 690 | 784 | 3,332 | −1,016 | 66 |
| `zap_as_built` | `per_rate` | 5,690 | 17.0% | 567 | 5,123 | 5,315 | 8,344 | −5,780 | 66 |

Mismatch shapes:

- **`per_line` fits `tax_value` about five times better than `per_rate`** in both variants. Most `per_rate` mismatches are 1p under (4,573 of `m1`'s 5,698), so ShopWired's `tax_value` behaves like per-line rounding. How QBO rounds is still for the M1 sandbox acceptance journey (COR-309) to settle.
- **Every `m1` `per_line` mismatch has a line with qty above 1**: 1,052 of 1,052. The 7dp unit price × qty step is the likely source, not VAT rounding alone.
- **Line-item VAT calculation off:** 1,018 of the 1,052 `m1` `per_line` mismatches have `line_item_vat_calculation` false, and 1,016 are `baseline` orders.
- **Zero-rate lines:** 46 of the 1,052 `m1` `per_line` mismatches mix 0% and 20% lines.
- **Discounted shipping:** two orders with discounted shipping mismatch only in `zap_as_built`, and only `zap_as_built` has the two +66p deltas. The likely cause is the Zap's pre-discount shipping figure overstating these orders, which is the case COR-295/D8 corrects.

Choices beyond parity that these results inform, such as the VAT mode, a total guard and `vat_mismatch` warnings, are held in COR-321.

## 4. Float money paths outside the sync path (COR-308 input)

These counts come from keyword greps and are lower bounds. None of these paths changes here.

- **`Money` (float-backed) importers, 57 files:**
  - Catalog Domain: 13
  - Catalog, other layers: 7
  - Inventory/Linnworks: 14
  - Contact submission and checkout recovery: 11
  - Quote conversion: 4
  - Sales: 1
  - Shared validators: 4
  - Dev console: 3
- **`TaxRate` (float percentage) importers, 15 files:**
  - Catalog pricing: 3
  - `Money`: 1
  - Inventory: 3
  - Linnworks purchase orders: 8
- **Arithmetic on unwrapped floats:**
  - Catalog margins: `ProductViewPricing`, `ResolvedVariationPrices`, `ProductPricingUpdatedNotification`.
  - Supplier costs: a float `===` comparison in `CostPriceBySupplierTransformer`.
  - Pricing commands: a VAT round-trip check, `Money::isVatRoundTripSafe` via `PriceCommandsVatRoundTripValidator`.
  - Google Ads: micros to currency, `cost_micros / 1_000_000`, in `GoogleAdsRowTransformer`.
- **Outbound float payloads:**
  - ShopWired price and product clients
  - Linnworks requests
  - Google and Bing conversion uploads
  - Mixpanel DTOs
- **Float holders:**
  - Products and pricing: 25 or more files
  - Price history: 3
  - Linnworks: 26 or more
  - Ad spend: 7
  - Mixpanel: 3
- **Numeric money columns, read back as floats:**
  - ShopWired orders: 22 or more
  - Products and pricing: 17, plus 2 percentages
  - Operations price periods: 3
  - Linnworks: 39 or more, at mixed scales `(10,2)`, `(10,4)` and `(12,4)`
  - Checkout and contact: 2

  Scales across these tables range from `(8,2)` to `(14,6)`.
- **ShopWired order ingest:** Section 1, steps 1 to 6.
