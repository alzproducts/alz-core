# Decimal money value object for the QuickBooks sync

Amounts the QuickBooks order sync builds are held in a thin Domain value object wrapping `BcMath\Number`. The float-backed `Money` stays until COR-308 moves it onto this type; `app/Domain/CLAUDE.md` names which each path uses.

- It is built only from decimal strings; platform values and VAT rates never pass through float arithmetic.
- It compares with `compare()`, never `===`.
- It rounds explicitly, half away from zero (`RoundingMode::HalfAwayFromZero`), to the caller's scale. Division runs at the default scale and is rounded afterwards, because `BcMath\Number` truncates at an explicit scale.
- An allocate helper makes split parts sum exactly to their total.
- One rounding rule throughout, the Zap's: per line, a 7dp net unit price and a 2dp line amount.

New money columns use `numeric(14,2)` for amounts and a wider scale for unit prices (at least 7dp when posted to QuickBooks) and rates.

## Considered Options

- **Floats:** rejected. Representation drift reaches the 2dp amounts QuickBooks taxes.
- **Integer pence:** rejected. VAT from gross and sub-penny unit prices need fractional intermediates, and both platforms exchange decimals.
- **`brick/money`:** rejected. Domain depends only on PHP built-ins and `Webmozart\Assert`.
- **Raw `BcMath\Number`:** rejected. Nothing would enforce the rounding and allocation rules.
