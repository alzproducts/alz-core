# Known Issues

Tracked issues that aren't blockers but should be documented for future investigation.

---

## ShopWired Customer Sync: Duplicate Emails

**Added:** 2026-01-15
**Severity:** Low
**Impact:** ~8 customers (0.01%) not synced

### Problem

ShopWired appears to have ~8 customers with duplicate emails (different customer IDs, same email address). Our `shopwired.customers` table has a unique constraint on `email`, so when the second customer with a duplicate email is synced, the INSERT fails.

### Symptoms

- `Unique constraint violation` warnings in logs during sync (PostgreSQL error 23505)
- DB count slightly lower than API "fetched" count
- No actual sync failures reported (constraint violations are caught and logged)

### Workarounds

None needed - 0.01% data loss is acceptable for current use cases.

### Future Fix Options

1. **Remove email unique constraint** - If ShopWired legitimately allows duplicate emails
2. **Add logging to identify duplicates** - Modify `EloquentCustomerRepository` to log the specific email on constraint violation, then investigate which accounts are affected
3. **Composite unique key** - Use `(external_id)` only, drop email uniqueness

### Investigation Notes

- Constraint violations occur during `updateOrCreate` which tries INSERT first
- Laravel's `updateOrCreate` doesn't use native PostgreSQL upsert
- The "saved" counter may incorrectly increment even when INSERT fails (potential minor bug)

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