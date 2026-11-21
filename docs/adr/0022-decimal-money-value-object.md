# Decimal money value object for the QuickBooks sync

Accounting amounts the QuickBooks order sync builds are held in a new thin Domain value object wrapping PHP's built-in `BcMath\Number`, not in floats, integer pence or a third-party money library. The existing float-backed `Money` stays as it is until COR-308 moves it onto this type.

The value object:

- is built only from decimal strings (the sync-source view's `numeric` columns). Platform values never pass through float arithmetic.
- compares with `compare()`, never `===`.
- rounds explicitly, half away from zero (`RoundingMode::HalfAwayFromZero`), to a scale the caller gives.
- has an allocate helper, so split parts sum exactly to their total.

The sync uses one rounding rule throughout, the Zap's: per line, a 7dp net unit price and a 2dp line amount (COR-295 D16, D3). Division runs at the default scale and is rounded afterwards, because `BcMath\Number` truncates at an explicit scale. VAT rates enter as decimal strings, not as the float `TaxRate`. This type changes only the representation: the Zap's arithmetic is kept, and its float-correction steps fall away because exact decimals never need them.

New money columns use `numeric(14,2)` for amounts, and a wider scale for unit prices and rates. A unit price posted to QuickBooks needs at least 7dp. Existing `numeric(14,6)` columns are not migrated here.

## Considered Options

- **Floats** (the current ingest, order VOs and `Money`): rejected. Representation drift reaches the 2dp amounts QuickBooks taxes.
- **Integer pence:** rejected. 20% VAT from gross (one sixth) and sub-penny net unit prices need fractional intermediates, and both ShopWired and QuickBooks exchange decimals.
- **`brick/money`:** rejected. Domain may depend only on PHP built-ins and `Webmozart\Assert`.
- **Raw `BcMath\Number` in the builder:** rejected. Nothing would enforce the rounding and allocation rules.

## Consequences

- `ext-bcmath` becomes a platform requirement, and every CI job installs it.
- Until COR-308 lands, Domain has two money types: `Money` (float, existing code) and this type (the sync path). `app/Domain/CLAUDE.md` names which one each path uses.
