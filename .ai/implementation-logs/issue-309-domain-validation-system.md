# Implementation Log: Domain Validation System

**GitHub Issue**: #309
**Plan Document**: .ai/plans/2026-03-18_309-domain-validation-system.md
**Status**: In Progress
**Started**: 2026-03-18
**Completed**: —

## Overview

Unified validation system with contracts, traits, and the first real validator (`SkuBelongsToProductValidator`). Replaces ad-hoc validation scattered across UseCases.

## Decision Log

### 2026-03-18
- **Decision**: Renamed traits to `*Trait` suffix (`ThrowsOnValidationFailureTrait`, `AggregatesChildResultsTrait`)
- **Why**: PHPStan symplify rule `explicitTraitSuffixName` enforces `*Trait` suffix. Codebase convention (e.g., `BasicProductTrait`).
- **Tradeoff**: Diverges from design report naming but matches mechanical enforcement.

- **Decision**: Added `trait.unused` ignore for `AggregatesChildResultsTrait` in `phpstan.neon`
- **Why**: No aggregate validator in this PR (deferred by design). Trait is infrastructure delivered ahead of consumers.

- **Decision**: Skipped `ValidationFailedExceptionTest`
- **Why**: Testing strategy says "Exception classes: Data containers. Nothing to test." Exception excluded from coverage in `phpunit.xml`.

- **Decision**: Used `array_flip` lookup in `SkuBelongsToProductValidator` instead of `array_filter` with `in_array`
- **Why**: O(n) lookup vs O(n×m) for large SKU lists. Report's `->skus()->contains()` API doesn't exist in codebase.

## Deviations from Plan

- Trait suffix: Plan used `ThrowsOnValidationFailure` / `AggregatesChildResults`, implementation uses `*Trait` suffix
- Exception test: Plan specified one, skipped per testing strategy
- `ValidationFailedException` properties: `public readonly` as planned, Pint auto-added `use` imports for `@see` references

## Blockers / Open Questions

- [x] PHPStan `trait.unused` for aggregate trait — resolved with `phpstan.neon` ignore
- [x] `#[CoversClass]` doesn't support traits — resolved by removing attribute, adding comment

## PR Notes

### What
Validation infrastructure (contracts, traits, exception) in `Domain/Shared/Validation/` + first validator (`SkuBelongsToProductValidator`) + `Product::allSkus()` method.

### Why
Validation logic scattered across UseCases with inconsistent return types and bespoke error handling. Unified system enforces consistent structure via interfaces and traits.

### Key Decisions
- Traits use `*Trait` suffix per codebase convention (diverges from design report)
- No aggregate validator example — infrastructure + docs explain how
- Exception test skipped per testing strategy (data containers not tested)

### Testing
- `ThrowsOnValidationFailureTraitTest` — anonymous class pattern, tests pass/fail paths
- `AggregatesChildResultsTraitTest` — anonymous class pattern, tests aggregation logic
- `ProductTest` — 6 new `allSkus()` tests (master only, variations, null SKUs, empty)
- `SkuBelongsToProductValidatorTest` — 7 tests covering pass/fail, orFail, reason, context
