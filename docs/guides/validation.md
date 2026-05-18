# Validation System Guide

## Overview

Validation logic was previously scattered across UseCases as ad-hoc checks with inline exception throws. This system provides a unified contract so every validator follows the same structure: parameterless `validate()`, rich result objects, two consumption paths.

**Infrastructure location**: `App\Domain\Shared\Validation\` (contracts + traits)

**Validator location**: Co-located with their domain concept in `Validators/` subdirectories (e.g., `App\Domain\Catalog\Product\Validators\`)

## Architecture

```
Domain/
├── Shared/Validation/
│   ├── Contracts/
│   │   ├── ValidatorInterface.php
│   │   └── DescribableValidationResultInterface.php
│   └── Concerns/
│       ├── ThrowsOnValidationFailureTrait.php      # Single results
│       └── AggregatesChildResultsTrait.php          # Aggregate results
├── Exceptions/
│   └── ValidationFailedException.php               # Thrown by orFail()
└── {Concept}/
    └── Validators/
        ├── SomeValidator.php                        # implements ValidatorInterface
        └── SomeResult.php                           # implements DescribableValidationResultInterface
```

**Namespace mapping**: The design report uses `Domain\Shared\Validation\`, the codebase uses `App\Domain\Shared\Validation\`.

## Key Design Decisions

- **Parameterless `validate()`**: Domain objects injected via constructor, enabling a shared interface and composability (aggregates call `validate()` on children generically). Validators are short-lived objects, not singletons.
- **Single result interface**: Every validation result implements `DescribableValidationResultInterface`. No tier selection, no ambiguity. Aggregates treat all children uniformly.
- **`orFail()` via trait only**: `ThrowsOnValidationFailureTrait` is the single source of truth for exception throwing. No class may implement its own `orFail()`. This prevents bespoke exception behaviour.
- **Exception is a dumb carrier**: `ValidationFailedException` receives pre-formatted `reason` and `context` from the result. It doesn't know if data came from one validator or five.
- **`reason()` is for developers**: Logs, Sentry, exception messages — NOT user-facing. Presentation builds its own messages from `context()`.

## Creating a Single Validator

### Step 1: Validator Class

```php
namespace App\Domain\{Concept}\Validators;

use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

final class FooValidator implements ValidatorInterface
{
    public function __construct(
        private readonly Bar $bar,           // Domain objects via constructor
        private readonly array $requiredItems,
    ) {}

    public function validate(): FooResult     // Covariant return type
    {
        // Validation logic here
        $failures = /* ... */;
        return new FooResult($failures);
    }
}
```

### Step 2: Result Class

```php
namespace App\Domain\{Concept}\Validators;

use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

final readonly class FooResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    public function __construct(
        private array $failures = [],
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function reason(): string
    {
        // Developer-facing description
        return 'Foo validation failed: ' . count($this->failures) . ' issues';
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        // Structured data for logging/Sentry
        return ['failures' => $this->failures];
    }

    // Domain-specific accessors for callers that need detailed data
    public function failures(): array
    {
        return $this->failures;
    }
}
```

### Step 3: Tests

Place in `tests/Unit/Domain/{Concept}/Validators/`. Test:
- All owned → passes
- Some missing → fails with correct domain-specific data
- Empty input → passes
- `orFail()` throws on failure with correct `reason()` and `context()`
- `orFail()` is no-op on success
- `reason()` includes meaningful count/description
- `context()` contains serializable data (strings, not objects)

## Creating an Aggregate Validator

Aggregate validators compose multiple child validators into a single result. See the full example in the [design report](../../reports/domain-validator-report.md) §Aggregate Validator Example.

### Step 1: Aggregate Validator

```php
final class CreateOrderAggregateValidator implements ValidatorInterface
{
    public function __construct(
        private readonly SkuBelongsToProductValidator $skuValidator,
        private readonly MoneyComparisonValidator $priceValidator,
    ) {}

    public function validate(): CreateOrderValidationResult
    {
        return new CreateOrderValidationResult(
            skuResult: $this->skuValidator->validate(),
            priceResult: $this->priceValidator->validate(),
        );
    }
}
```

### Step 2: Aggregate Result

```php
final class CreateOrderValidationResult implements DescribableValidationResultInterface
{
    use AggregatesChildResultsTrait;  // Provides passed(), failed(), reason(), context(), orFail()

    public function __construct(
        private readonly SkuBelongsToProductResult $skuResult,
        private readonly MoneyComparisonResult $priceResult,
    ) {}

    /** @return array<string, DescribableValidationResultInterface> */
    protected function childResults(): array
    {
        return [
            'sku_validation' => $this->skuResult,     // Keys become context() keys
            'price_validation' => $this->priceResult,
        ];
    }

    // Domain-specific accessors for individual child results
    public function skuResult(): SkuBelongsToProductResult { return $this->skuResult; }
    public function priceResult(): MoneyComparisonResult { return $this->priceResult; }
}
```

The trait joins failed reasons with `'; '` and nests failed contexts under their keys. `orFail()` passes the aggregated data to `ValidationFailedException`.

## Consumption Patterns

### Soft Path — Inspect Result

```php
$result = (new SkuBelongsToProductValidator(
    product: $product,
    requiredSkus: $skus,
))->validate();

if ($result->failed()) {
    $missing = $result->missingSkus();   // Domain-specific accessor
    $context = $result->context();        // Structured data for logging
    // Handle as appropriate for this UseCase
}
```

### Strict Path — One-Liner

```php
(new SkuBelongsToProductValidator(
    product: $product,
    requiredSkus: $skus,
))->validate()->orFail();
// Throws ValidationFailedException if failed, no-op if passed
```

## Domain vs Application Validators

| Type | Dependencies | Location | Construction |
|------|-------------|----------|-------------|
| Domain | Pure domain objects only | `Domain/*/Validators/` | Direct `new` in UseCase |
| Application | Domain objects + services | `Application/*/Validators/` | UseCase passes DI-resolved services via constructor |

Application validators follow the same contracts — `implements ValidatorInterface`, returns `DescribableValidationResultInterface`. The only difference is the constructor accepts services alongside domain data.

## Naming Conventions

| Suffix | Purpose | Linting |
|--------|---------|---------|
| `*Validator` | Single validators | PHPArkitect Rule 9 (Domain), Application naming rule |
| `*AggregateValidator` | Aggregate validators | Enables future linting rules |
| `*Result` | Result classes (co-locate with validator) | Already in Application naming rule |

## Planned Linting Rules

Documented in the [design report](../../reports/domain-validator-report.md) §Linting Rules. Deferred until more validators exist to justify the overhead:

1. **Validator placement** — `*Validator` classes must be in a `Validators` namespace segment
2. **Directory contents** — classes in `Validators/` must end with `*Validator` or `*Result`
3. **Validate method** — `*Validator` classes must have a public `validate()` method
4. **Trait enforcement** — single results must use `ThrowsOnValidationFailureTrait`, aggregate results must use `AggregatesChildResultsTrait`
5. **Override protection** — `#[Override]` on `orFail()` should be disallowed
6. **Aggregate naming** — `*AggregateValidator` must use `AggregatesChildResultsTrait`