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