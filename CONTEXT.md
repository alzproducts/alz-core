# alz-core

The backend for ALZ — a Laravel/Octane service that syncs catalog, orders, and operational data between ShopWired (storefront), Linnworks (inventory + warehouse), and various marketing/analytics vendors. Clean Architecture: Presentation → Infrastructure → Application → Domain (outer-to-inner; dependencies point inward).

This file is a project glossary, not a how-to. Definitions describe what something **is**, not how it's implemented. For architecture, see [`docs/architecture-overview.md`](docs/architecture-overview.md). For decisions, see [`docs/adr/`](docs/adr/).

## Language

### Catalog & ShopWired sync

**Margin tier**:
One of four mutually-exclusive labels (`1 - Low margin`, `2 - Standard margin`, `3 - High margin`, `4 - Unknown margin`) assigned to a ShopWired product on `custom_label_1`.

**Margin midpoint**:
The value `(net_margin_single_unit_min + net_margin_single_unit_max) / 2` compared against margin-tier thresholds. Smooths single-outlier variations without computing a true per-variation average.

**ShopWired custom_label_N**:
ShopWired's `custom_label_N` series of single-select string custom fields on products. Two known owners in this codebase: `custom_label_4` → Best Sellers, `custom_label_1` → Margin tier. Design intent: each field is written by exactly one sync (see **Drift** below for why).
_Avoid_: treating these as value-list/array fields. Despite the "label" naming, they are single-select strings — the confusion previously produced a silent-write bug.

**Drift**:
The state when a product's current `custom_label_N` value differs from the value the sync would compute now. Detected by a single SQL query per sync and resolved by dispatching per-product update jobs.

**Best Sellers label**:
The string `"Best Sellers"` written to `custom_label_4` for products with `popularity_rank <= 2`.

### Pricing & margin

**Net margin (single unit)**:
Worst-case gross margin % when a single unit of a product absorbs the full free-delivery shipping cost. Distinct from `profit_margin`, which excludes shipping. Sourced from `catalog.products_view.net_margin_single_unit_min/max`.

**Effective price**:
The price a customer actually pays — `sale_price` when `is_on_sale`, otherwise `price`. Its VAT-stripped sibling `effective_price_net` is what the view uses for margin computations.

## Relationships

- A **drift query** computes a target **margin tier** per eligible product and returns only those whose current label differs from the target
- The design intent is for each **ShopWired custom_label_N** to be written by exactly one sync feature; multi-writer co-ownership creates clobbering risk because these are single-select string fields, not value-lists
- The **margin midpoint** is derived from the **net margin (single unit)** min/max columns in `catalog.products_view`

## Example dialogue

> **Dev:** "If we change a product's price, does its **margin tier** update right away?"
> **Domain expert:** "No. The **margin tier** is recomputed by the daily **drift query** at 04:45. The **effective price** change is reflected in `products_view.net_margin_single_unit_min/max` immediately because it's a view, but the **drift query** only fires on the schedule — so `custom_label_1` lags by up to 24h."

> **Dev:** "Can two syncs write to the same **ShopWired custom_label_N**?"
> **Domain expert:** "By design no — we want each `custom_label_N` written by exactly one sync. We learned the hard way that these are single-select string fields; if two syncs wrote to the same field they'd clobber each other on every cycle."

## Flagged ambiguities

- "label" can mean (1) a **ShopWired custom_label_N** field, or (2) the **string value** written to it. Disambiguate in conversation by saying "the `custom_label_1` field" vs "the `1 - Low margin` label value".
