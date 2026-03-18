# Domain Shared Validation

Cross-cutting validation infrastructure for all validators in the codebase.

## Rules

- Validators live with their domain concept in `Validators/` subdirectories — never here
- Single results: `use ThrowsOnValidationFailureTrait`
- Aggregate results: `use AggregatesChildResultsTrait` (includes `ThrowsOnValidationFailureTrait`)
- Never implement `orFail()` manually — the trait is the single source of truth
- Naming: `*Interface` (PHPArkitect Rule 6), `*Trait` (PHPStan symplify)

## Design Report

Implements `Domain/Shared/Validation/` from `.ai/reports/domain-validator-report.md`.
