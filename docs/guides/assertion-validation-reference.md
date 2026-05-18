# Assertion & Validation Reference

## Runtime Assertions (Development Only)

Zero cost in production when `zend.assertions=-1`

| Tool | Use Case |
|------|----------|
| `assert()` | Built-in PHP, compiles out in production |
| `webmozart/assert` | Fluent API with 100+ methods, PHPStan integration |

**Use for:** Internal contracts, preconditions in private methods, class invariants, logical impossibilities

**Never for:** User input, API parameters, security checks, business validation

## Static Analysis (Compile-time)

Pure documentation, zero runtime cost.

| Tool | Use Case |
|------|----------|
| PHPStan annotations | `@phpstan-assert`, `@phpstan-assert-if-true` |
| Larastan | PHPStan + Laravel extensions |

**Use for:** Type narrowing at Level 8, custom validation function contracts

## Testing Assertions (Test-only)

| Tool | Use Case |
|------|----------|
| Pest | Modern test framework with expectation API |
| PHPUnit | Traditional assertions |

**Use for:** Verifying behavior in test suites

## Validation (Always Active)

Remains active in production, handles untrusted input.

| Tool | Use Case |
|------|----------|
| Laravel Validator | Framework-integrated, 80+ rules, Form Requests |

**Use for:** User input, API requests/responses, external data, security boundaries

## Decision Tree

```
Is the data external/untrusted?
    → YES: Laravel Validator
    → NO: ↓

Is this an internal contract/precondition?
    → YES: webmozart/assert
    → NO: ↓

Need PHPStan type narrowing?
    → YES: @phpstan-assert annotations
    → NO: ↓

Testing behavior?
    → YES: Pest expectations
```

## Layer-Specific Rules

| Layer | Validation Approach |
|-------|---------------------|
| Domain | `webmozart/assert` for invariants, domain exceptions for business rules |
| Application | Let exceptions bubble, validate orchestration contracts |
| Infrastructure | Laravel Validator for external data, translate to domain exceptions |
| Presentation | Form Requests for HTTP input, validate command arguments |
