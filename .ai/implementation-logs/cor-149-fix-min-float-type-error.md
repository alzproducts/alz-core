# Implementation Log: COR-149 — Fix Min(0.01) float type error on QuoteConversionRequestDTO value field

## Issue Context

`QuoteConversionRequestDTO::$value` (float) is annotated with `#[Min(0.01)]`. Spatie's `Min::__construct` is typed `int|ExternalReference`, so PHPStan (`argument.type`) flags this. Fix is to replace `Min(0.01)` with `GreaterThan(0)`, which is typed for numeric comparisons and matches the docblock intent ("must be positive"). Single-file edit.

## Environment

- Worktree: `/Users/tom/code/IdeaProjects/alz-core/.claude/worktrees/issue-cor-149`
- Branch: `bugfix/cor-149-fix-min001-float-type-error-on-quoteconversionrequestdto`
- Base: `develop` (`e53a49e4`)
- Deps: installed

## Implementation

- Replace `Min` import with `GreaterThan` in `app/Presentation/Http/Api/DTOs/QuoteConversionRequestDTO.php`.
- Replace `#[Required, Numeric, Min(0.01)]` with `#[Required, Numeric, GreaterThan(0)]` on `$value`.

## Lint Results

- Pint: passed
- PHPStan (level max): No errors (previously `argument.type` on line 29)
- PHPArkitect: No violations
- Deptrac: 0 violations
- TLint: LGTM

## Test Results

- `make test-quick` (Domain suite, no external deps): 1672 passed / 3071 assertions / 9.04s
- No existing tests reference `QuoteConversionRequestDTO`; per dispatch guardrails no new tests added.

## CI Status

## PR Notes
