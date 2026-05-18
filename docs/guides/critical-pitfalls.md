# Critical Pitfalls

Silent-but-serious mistakes that cause hard-to-diagnose bugs. **Check during PR reviews.**

---

## Date Arithmetic: Month Windows

**Never:** Direct month subtraction for date range windows
```php
// WRONG - Creates gaps at month boundaries
$to = Carbon::now()->subMonths(1);
$from = $to->subMonth();

// Also wrong with DateTimeImmutable
$to = $now->modify('-1 month');
$from = $to->modify('-1 month');
```

**Why:** Month arithmetic doesn't maintain end-of-month semantics.
- Mar 31 → `subMonth()` → Feb 28 (not Feb 31)
- Feb 28 → `subMonth()` → Jan 28 (not Jan 31)
- **Result:** Jan 29-31 never synced, creating 3-day data gaps

**Instead:** Anchor to start-of-month first
```php
// CORRECT - Gap-free calendar month windows
$from = Carbon::now()->startOfMonth()->subMonths($monthsAgo + 1);
$to = Carbon::now()->startOfMonth()->subMonths($monthsAgo);
```

**Applies to:** Backfill commands, sync jobs, analytics queries, reporting date ranges

---

## Adding New Pitfalls

When you discover a silent bug pattern:
1. Add it here with Never/Why/Instead format
2. Add brief reference to CLAUDE.md "Common Pitfalls" section
3. Consider if existing code needs auditing
