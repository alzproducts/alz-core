# Testing Strategy

> **Philosophy**: Test what matters, not what the type system already guarantees.

This document defines the testing strategy for alz-core, optimised for a solo developer using strict typing, PHPStan Level max, and Clean Architecture.

---

## Context: Why This Strategy Exists

Traditional testing advice ("aim for 80% coverage", "test everything") was developed for:
- Large teams where anyone might touch any code
- Dynamic languages without static analysis
- Codebases without architectural boundaries

Our context is different:
- **Solo developer** with full codebase knowledge
- **PHPStan Level max** catches type errors at compile time
- **Strict types** prevent coercion bugs
- **Clean Architecture** makes layers explicit and testable in isolation
- **PHPArkitect** prevents architectural violations

This changes the calculus. Many traditional tests verify things the type system already guarantees. Our strategy focuses testing effort where it provides genuine confidence.

---

## The Core Principle

**Tests exist to catch bugs that static analysis cannot.**

Static analysis catches:
- Type mismatches
- Null reference errors
- Missing methods
- Interface violations
- Architectural boundary violations

Tests should catch:
- Incorrect business logic
- Wrong calculations
- Edge case handling
- Integration contract violations
- Behavioural regressions

If a test only verifies something PHPStan already guarantees, it provides ceremony, not confidence.

---

## Layer-Specific Policies

Clean Architecture gives us natural boundaries. Each layer has different characteristics and therefore different testing needs.

### Domain Layer (`app/Domain`)

**What lives here**: Value objects, entities, domain exceptions, validation rules, business calculations, domain interfaces (contracts).

**Why it matters most**: This is pure business logic with zero framework dependencies. Bugs here are logic bugs—the most expensive kind. The type system can verify *types* but not *correctness*.

| Aspect | Policy |
|--------|--------|
| Coverage Target | 90%+ |
| Mutation Testing | Yes, strict (MSI 85%+) |
| Test Type | Unit tests |
| Mocking | None—these are pure functions |
| Test Location | `tests/Unit/Domain/` |

**What to test**:
- Value object validation (edge cases, boundary values)
- Business calculations (conversions, transformations)
- Domain rules and constraints
- Exception conditions

**Example**: `CampaignMetrics` converting micros to dollars—PHPStan knows it returns `float`, but only a test verifies the division is correct.

---

### Application Layer (`app/Application`)

**What lives here**: UseCases (orchestration), Services (reusable business logic), Jobs (queue entry points), Transformers.

**Why it matters**: This layer contains business workflows and data transformations. UseCases orchestrate; Services compute.

| Aspect | Policy |
|--------|--------|
| Coverage Target | 70%+ |
| Mutation Testing | Yes, for Services/Transformers only |
| Test Type | Unit tests with minimal mocking |
| Mocking | Domain interfaces only, not infrastructure |
| Test Location | `tests/Unit/Application/` |

**What to test**:
- Transformation logic (data mapping, formatting)
- Business workflow branches
- Orchestration decisions (when X, do Y)
- Error handling paths

**What to skip or minimise**:
- Pure delegation (UseCase just calls one service)
- Job dispatch mechanics (framework behaviour)

**The UseCase vs Service distinction for testing**:

| Type | Contains | Testing Focus |
|------|----------|---------------|
| UseCase | Workflow orchestration | Test branching logic, skip pure delegation |
| Service | Reusable business logic | Test calculations, transformations thoroughly |

A `SyncAdSpendUseCase` that just calls fetch → transform → send in sequence has little testable logic. The `AdSpendTransformer` it calls has real logic worth testing.

**Mutation testing implication**: UseCases don't require mutation testing—they're orchestration code with mostly delegation and logging. Don't chase mutation scores on UseCase classes; focus mutation testing on Services with actual business logic.

---

### Infrastructure Layer (`app/Infrastructure`)

**What lives here**: API clients, repository implementations, external service integrations, framework adapters.

**Why the approach changes**: Infrastructure code is mostly "glue"—calling external APIs with the right parameters. Heavy mocking tests here verify mock setup, not real behaviour.

| Aspect | Policy |
|--------|--------|
| Coverage Target | None enforced |
| Mutation Testing | No |
| Test Type | Integration tests at boundaries |
| Mocking | `Http::fake()` at external boundary only |
| Test Location | `tests/Integration/Infrastructure/` |

**What to test**:
- One happy path (API returns expected data, we parse it correctly)
- One error path (API returns error, we handle it correctly)
- Rate limiting behaviour (if applicable)

**What NOT to test**:
- Every internal method
- Parameter construction in isolation
- Retry logic details (trust the framework)

**Example**: `ReviewsIoClient` needs 2-3 tests:
1. "Fetches ratings successfully" (happy path with `Http::fake`)
2. "Handles API errors gracefully" (error path)
3. "Validates SKU input" (if validation is complex)

Not fifteen tests for URL building, parameter encoding, header setting, etc.

---

### Presentation Layer (`app/Http`, `app/Console`, `app/Presentation`)

**What lives here**: Controllers, console commands, middleware, request/response handling.

**Why minimal testing**: Presentation is thin—it receives input, delegates to Application layer, formats output. Most bugs here are caught by:
- Route/controller binding (Laravel throws if broken)
- Type hints on request parameters
- PHPStan on return types

| Aspect | Policy |
|--------|--------|
| Coverage Target | None enforced |
| Mutation Testing | No |
| Test Type | Feature tests / smoke tests |
| Mocking | Full application stack |
| Test Location | `tests/Feature/` |

**What to test**:
- Routes are registered and accessible
- Authentication/authorisation works
- Response shape is correct (JSON structure)
- Critical user journeys work end-to-end

**What to skip**:
- Every controller method in isolation
- Middleware that just delegates
- Console commands that dispatch jobs

---

### Excluded from Coverage

Some code provides no value when tested:

| Exclusion | Reason |
|-----------|--------|
| Service Providers | Configuration, not logic. PHPStan catches binding errors. |
| Exception classes | Data containers. Nothing to test. |
| Simple DTOs | No logic. Type system handles structure. |
| Git Hooks | Development tooling, not production code. |
| Config files | Static data. |

These are explicitly excluded in `phpunit.xml` to avoid skewing coverage metrics.

---

## Mutation Testing Strategy

Mutation testing verifies test *quality*, not just quantity. A test that doesn't fail when code changes isn't providing confidence.

**Where to apply mutation testing**:
- Domain layer (strict thresholds)
- Application Services and Transformers (moderate thresholds)

**Where NOT to apply**:
- Infrastructure (mutations in HTTP calls don't indicate test weakness)
- Presentation (mutations in response formatting rarely matter)
- Framework glue (testing Laravel, not our code)

**Recommended thresholds**:

| Layer | MSI Target | Covered MSI Target |
|-------|------------|-------------------|
| Domain | 85%+ | 90%+ |
| Application Services | 70%+ | 80%+ |

---

## What This Strategy Optimises For

**Optimised for**:
- Confidence in business logic correctness
- Fast feedback loops (fewer tests = faster CI)
- Maintainable test suite (tests that don't break on refactors)
- Solo developer efficiency

**Explicitly not optimised for**:
- Coverage percentages as vanity metrics
- "Testing culture" demonstrations
- Catching every possible bug (diminishing returns)

---

## Decision Framework: Should I Write a Test?

When adding new code, ask:

```
1. Is this Domain logic?
   → Yes: Write thorough unit tests with edge cases
   
2. Is this Application logic with business rules?
   → Yes: Write unit tests for the rules/transformations
   → If pure orchestration with no branching: minimal or skip
   
3. Is this Infrastructure code?
   → Write 1-2 integration tests at the boundary
   → Don't unit test internal implementation
   
4. Is this Presentation code?
   → Write a feature test if it's a critical path
   → Skip if it's just delegation

5. Would PHPStan catch this bug?
   → Yes: Probably don't need a test
   → No: Probably do need a test
```

---

## When to Revisit This Strategy

This strategy assumes current context. Revisit if:

- **Team grows**: More developers = more need for tests as documentation and safety nets
- **Code churn increases**: High-change areas benefit from more tests
- **Bugs escape to production**: Each escaped bug suggests a testing gap
- **Static analysis changes**: If we lower PHPStan level, we need more tests

---

## Summary

| Layer | Coverage | Mutation | Approach |
|-------|----------|----------|----------|
| Domain | 90%+ | Yes (85%+) | Thorough unit tests |
| Application | 70%+ | Services only | Business logic focus |
| Infrastructure | — | No | Integration tests only |
| Presentation | — | No | Smoke/feature tests |

**The goal**: A test suite that's small, fast, and catches real bugs—not one that's large, slow, and catches mock configuration errors.
