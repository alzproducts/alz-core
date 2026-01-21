# Known Issues

Tracked issues that aren't blockers but should be documented for future investigation.

---

## ~~ShopWired Customer Sync: Duplicate Emails~~ (RESOLVED)

**Added:** 2026-01-15
**Resolved:** 2026-01-15
**Resolution:** Dropped unique constraint on email, replaced with regular index. Migration: `2026_01_14_224430_drop_unique_email_on_shopwired_customers`

---

## ShopWired: searchByEmail Returns Arbitrary Result for Duplicates

**Added:** 2026-01-15
**Severity:** Low
**Impact:** Edge case (~7 customers with duplicate emails)

### Problem

ShopWired allows the same email on multiple customer accounts (e.g., trade + non-trade). `CustomerClient::searchByEmail()` returns `?Customer` with `withCount(1)`, so when duplicates exist it returns whichever ShopWired returns first (arbitrary).

### Symptoms

- Searching by email may return "wrong" customer (older instead of newer, or non-trade instead of trade)
- No error raised — silently returns one of the matches

### Workarounds

None needed currently. Use `external_id` for precise customer lookup.

### Future Fix Options

1. **Add `searchAllByEmail(): array`** — new method returning all matches
2. **Sort by created_at desc** — prefer most recent customer
3. **Accept limitation** — document and move on (current approach)

---

## BasicProductInterface and ProductVariation

**Added:** 2026-01-21
**Severity:** Low
**Impact:** ProductVariation cannot share polymorphism with Product for pricing operations

### Problem

ShopWired variations can have nullable prices with special semantics:
- `null` = inherit parent product's price
- `0.00` = temporarily removed from sale

This creates semantic issues with `BasicProductInterface` methods like `isOnSale()` and `effectivePrice()` which require the parent product's context to resolve correctly when price is null.

### Symptoms

- `ProductVariation` does not implement `BasicProductInterface`
- Cannot use unified `BasicProductInterface` type for both Products and Variations
- Methods like `isOnSale()` would return incorrect results without parent context

### Workarounds

ProductVariation has its own standalone methods (`price()`, `salePrice()`, etc.) that return nullable values. Callers must handle the null case by looking up the parent product's price.

### Future Fix Options

1. **Add parent-aware methods** — `effectivePriceWithParent(Product $parent): float`
2. **Create VariationWithParent wrapper** — Composite object that holds both variation and parent
3. **Accept limitation** — Keep interfaces separate, document the difference (current approach)

---

## Template

```markdown
## [Short Title]

**Added:** YYYY-MM-DD
**Severity:** Low | Medium | High
**Impact:** [Brief description]

### Problem
[What's happening]

### Symptoms
[How to recognize it]

### Workarounds
[Current mitigation if any]

### Future Fix Options
[Possible solutions]
```