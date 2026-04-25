---
paths:
  - "app/Domain/**/Validators/*.php"
---

# Domain — Validator + Validation-Result Rules

## Placement

- DO place validators in the concept's own `Validators/` subdirectory (e.g. `app/Domain/Catalog/Product/Validators/`, `app/Domain/Shared/Money/Validators/`). DO NOT add new validators under `app/Domain/Shared/Validation/` — that directory holds validation infrastructure (contracts, traits) only.

## Result Classes

- DO `use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait` on single-result classes that need an `orFail()` entry point.
- DO `use App\Domain\Shared\Validation\Concerns\AggregatesChildResultsTrait` on aggregate-result classes that compose multiple child results — the aggregate trait already includes `ThrowsOnValidationFailureTrait`, so do not compose both.
- DO NOT implement `orFail()` manually — the trait is the single source of truth. If a result class needs `orFail()`, compose the trait; do not hand-roll.

Canonical:
- Single result: `MoneyEqualsValidator` + `MoneyEqualsResult`.
- Aggregate result: `VatRoundTripValidator` + `VatRoundTripAggregateResult`.
