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
ShopWired's `custom_label_N` series of single-select string custom fields on products. Three known owners in this codebase: `custom_label_0` → Credit tier, `custom_label_1` → Margin tier, `custom_label_4` → Best Sellers. Design intent: each field is written by exactly one **drift sync** (see **Drift** below for why).
_Avoid_: treating these as value-list/array fields. Despite the "label" naming, they are single-select strings — the confusion previously produced a silent-write bug.

**Drift**:
The state when a product's current synced attribute — a `custom_label_N` value or a filter value set — differs from the value the owning sync would compute now. Detected by a single SQL query per sync and resolved by dispatching per-product update jobs.

**Drift sync**:
A scheduled sync that detects **drift** with one SQL query and corrects it by dispatching one update job per drifted product. Eight exist: three label syncs (credit tier, margin tier, Best Sellers) and five filter syncs (VAT relief, rating, offers, shipping offers, shipping options).

**Credit tier**:
One of three labels (`Credit - Tier 1` … `Credit - Tier 3`) assigned to a ShopWired product on `custom_label_0` from the credit product popularity snapshot. A product with no credit sales in the latest snapshot has no tier — its field is cleared.

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
- A **tracking number** belongs to exactly one **number pool**
- A **tracking number** is shown to at most one visitor within the **attribution window**
- A **call-attributed conversion** carries a **click ID** resolved from the visit record, not from a contact submission
- A **collision** means attribution is ambiguous — the row is excluded, not best-guessed

## Example dialogue

> **Dev:** "If we change a product's price, does its **margin tier** update right away?"
> **Domain expert:** "No. The **margin tier** is recomputed by the daily **drift query** at 04:45. The **effective price** change is reflected in `products_view.net_margin_single_unit_min/max` immediately because it's a view, but the **drift query** only fires on the schedule — so `custom_label_1` lags by up to 24h."

> **Dev:** "Can two syncs write to the same **ShopWired custom_label_N**?"
> **Domain expert:** "By design no — we want each `custom_label_N` written by exactly one sync. We learned the hard way that these are single-select string fields; if two syncs wrote to the same field they'd clobber each other on every cycle."

### Offline conversion tracking

**Offline conversion**:
An event reported back to an ad platform (Google Ads, Bing Ads) indicating that a user who clicked an ad later performed a valuable action (qualified lead, quote issued). Uploaded via platform-specific APIs using the click ID captured at landing time.

**Click ID**:
A platform-specific identifier appended to the landing URL when a user clicks a paid ad. `gclid` (Google), `msclkid` (Bing/Microsoft), `fbclid` (Facebook). Stored on the contact submission at form-submit time. A submission may carry click IDs from multiple platforms.

**Qualified Lead** (conversion type: `lead_received`):
A staff-initiated action marking a contact submission as a genuine sales lead. Triggers async upload of the conversion to every ad platform that has a click ID on that submission. Distinct from **Quote Issued** (`quote_issued`), which carries a monetary value and staff-supplied timestamp.

**Conversion goal name** (Bing-specific):
The exact string name of an `OfflineConversionGoal` configured in the Bing Ads UI. Must match `ConversionName` in the upload payload case-sensitively. A mismatch causes silent drop — no error returned.
_Avoid_: confusing with Google's numeric **conversion action ID**, which serves the same purpose but is an integer, not a string.

**Ad platform**:
One of the advertising networks that receives offline conversion uploads (`Google`, `Bing`). Each platform tracks its upload status independently per submission — a submission can succeed on Google and fail on Bing.

### Call tracking

**Tracking number**:
A Twilio phone number from a rotating pool, shown to eligible visitors (valid click ID + marketing consent) on the Contact Us page. Links the visitor's ad click to a subsequent phone call via the number they dialled.
_Avoid_: "dynamic number", "virtual number"

**Number pool**:
The set of active Twilio tracking numbers available for rotation. Sequential assignment, modulo pool size. A number is never assigned to two different visitors within the **attribution window**.

**Attribution window**:
A single configurable duration (default: 6 hours) governing both visit deduplication (same click ID gets same number back) and call-to-visit merging (calls attributed to visits within this window). Unified to reduce operational complexity.
_Avoid_: "merge window", "dedup window" (these are both facets of the single attribution window)

**Call-attributed conversion**:
An offline conversion where the click ID originates from a `call_tracking_visits` record (via phone call) rather than a `contact_submissions` record (via web form). Submitted to ad platforms through the same upload infrastructure but via an independent use case path.

**Collision**:
The error state where >1 visit exists for the same tracking number within the attribution window. Should be architecturally impossible at current volume (pool size guarantees ~5 day reuse). If detected: Sentry alert, row excluded from dashboard (junk data, not actionable).

### Checkout & basket recovery

**Basket snapshot**:
A server-side capture of a customer's checkout intent (nominated delivery date, gift note, VAT-relief form submission) fired by JavaScript on checkout-link click. Stored in `checkout.basket_snapshots`. Workaround for ShopWired's Safari bug that drops `basket_comments` from the order payload.

**Basket recovery match**:
A probabilistic match between a **basket snapshot** and a ShopWired order, keyed on IP address (exact) + order total (exact) + a short time window (order placed within 30 minutes after the snapshot). No shared key exists between the two systems; this is the best-effort heuristic.
_Avoid_: treating a match as deterministic — multiple orders from the same IP/total within the window produce a `multiple_orders_placed_within_timeframe` flag for human review.

## Relationships (checkout)

- A **basket snapshot** captures the customer's FINAL pre-checkout intent per IP (latest write wins, one snapshot per IP within the scope window)
- A **basket recovery match** flags per-field recovery needs: "snapshot captured X AND no matched order carries X" for delivery date, gift note, and VAT relief independently
- The **basket recovery match** reuses `shopwired.orders_deduplicated` for order dedup (one row per reference), extracting the IP downstream

## Flagged ambiguities

- "label" can mean (1) a **ShopWired custom_label_N** field, or (2) the **string value** written to it. Disambiguate in conversation by saying "the `custom_label_1` field" vs "the `1 - Low margin` label value".
