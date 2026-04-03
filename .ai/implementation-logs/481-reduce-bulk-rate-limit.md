# Implementation Log: #481 — Reduce shopwired-api-bulk rate limit to 20 req/min

## Issue Context
`UpdateProductRatingFilterJob` uses the `shopwired-api-bulk` limiter (30 req/min). Each bulk job triggers a ShopWired webhook on the standard `shopwired-api` limiter (55 req/min), producing ~60 req/min total. Reducing bulk to 20 req/min caps total at ~40 req/min, leaving headroom for normal API traffic.

## Environment
- Worktree: /Users/tom/code/IdeaProjects/alz-core/.claude/worktrees/issue-481
- Branch: feature/481-reduce-bulk-rate-limit
- Deps: installed

## Implementation
- Changed `Limit::perMinute(30)` → `Limit::perMinute(20)` in `RateLimitServiceProvider::registerQueueLimiters()` (line 59)

## Lint Results
- Pint: pass
- PHPStan: no errors
- PHPArkitect: no violations
- Deptrac: 0 violations
- TLint: LGTM

## Test Results
- Domain test suite: 1,435 passed (2,655 assertions) in 8.52s

## CI Status
