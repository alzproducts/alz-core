# Domain Validation System Design (v3)

## Design Philosophy

Validation is a cross-cutting concern that touches many layers. Without upfront constraints, validators drift — inconsistent return types, scattered placement, bespoke error handling per UseCase. This design imposes mechanical constraints via interfaces, traits, and linting rules so that every validator in the codebase follows the same structural contract regardless of who writes it.

**Key principles:**

- One interface for all validation results — no tier selection, no ambiguity
- Interfaces are constraints, not speculative generality — defined upfront to prevent drift
- Validators are pure domain logic unless they require infrastructure (I/O, repositories), in which case they live in Application
- Validators receive domain objects via constructor — `validate()` is parameterless, enabling a shared interface and composability
- Results carry both the success signal and the failure data — callers never need a separate call to find out what went wrong
- Observability is built into the strict path via the exception pipeline, not baked into validators as side effects
- Aggregate validators are structurally distinct from single validators — naming and traits enforce this mechanically

---

## Directory Structure

```
Domain/
├── Exceptions/
│   └── ValidationFailedException.php
├── Shared/
│   ├── Validation/
│   │   ├── Contracts/
│   │   │   ├── DescribableValidationResult.php
│   │   │   └── Validator.php
│   │   └── Concerns/
│   │       ├── ThrowsOnValidationFailure.php
│   │       └── AggregatesChildResults.php
│   └── Money/
│       └── Validators/
│           ├── MoneyComparisonValidator.php
│           └── MoneyComparisonResult.php
└── Platform/
    ├── Product/
    │   ├── Product.php
    │   └── Validators/
    │       ├── SkuBelongsToProductValidator.php
    │       └── SkuValidationResult.php
    └── Order/
        └── Validators/
            ├── CreateOrderAggregateValidator.php
            └── CreateOrderValidationResult.php
```

All validation infrastructure lives under `Domain/Shared/Validation/` — contracts and concerns as co-located subdirectories following existing codebase conventions.

Validators are co-located with the domain concept they validate, inside a `Validators/` subdirectory. Universal concepts live under `Domain/Shared/`. Result classes co-locate with their validator. The linting rule matches on the `Validators` namespace segment at any depth, so one rule covers the entire tree.

There is no top-level `Domain/Validators/` catch-all. Every validator belongs to a specific domain concept. If you can't determine which concept owns it, the domain model is missing a concept.

Aggregate validators live with the domain concept that owns the process they validate (e.g., an order creation aggregate lives with Order, even though it calls validators from Product and Money).

---

## Contracts

### Validator

The shared interface for all validators. Parameterless `validate()` is possible because domain objects are injected via the constructor, making validators short-lived objects constructed with specific data rather than stateless services.

```php
<?php

declare(strict_types=1);

namespace Domain\Shared\Validation\Contracts;

interface Validator
{
    public function validate(): DescribableValidationResult;
}
```

All validators — single and aggregate — implement this. PHP supports covariant return types, so concrete validators can declare more specific return types (e.g., `validate(): MoneyComparisonResult`) while satisfying the interface.

---

### DescribableValidationResult

The single interface for all validation results. Every validator returns an implementor of this interface.

```php
<?php

declare(strict_types=1);

namespace Domain\Shared\Validation\Contracts;

use Domain\Exceptions\ValidationFailedException;

interface DescribableValidationResult
{
    public function passed(): bool;

    public function failed(): bool;

    /**
     * Human-readable failure reason for developer/ops observability.
     *
     * Defined once on the result class — not reconstructed by each UseCase.
     * This is NOT user-facing. Presentation builds its own messages from context().
     */
    public function reason(): string;

    /**
     * Structured context for logging and error tracking (e.g. Sentry).
     *
     * @return array<string, mixed>
     */
    public function context(): array;

    /**
     * Throw if the validation failed. No-op if passed.
     *
     * Implementation is provided by the ThrowsOnValidationFailure trait
     * and enforced by linting — do not implement manually.
     *
     * @throws ValidationFailedException
     */
    public function orFail(): void;
}
```

Two methods for the boolean state (`passed()` and `failed()`) because callers read more naturally with the method that matches their branch. Concrete classes decide which is the canonical primitive — the other delegates.

`reason()` provides a consistent message authored once on the result class. For aggregate results, `reason()` aggregates child failure descriptions. `context()` provides structured data for Sentry/logging. For aggregate results, `context()` nests child context data. `orFail()` converts a failed result into an exception — the strict consumption path.

---

## Traits

### ThrowsOnValidationFailure

The mandated implementation of `orFail()`. Linting enforces that every class implementing `DescribableValidationResult` uses this trait. No class may provide its own `orFail()` — this is what prevents bespoke exception behaviour drifting across the codebase.

```php
<?php

declare(strict_types=1);

namespace Domain\Shared\Validation\Concerns;

use Domain\Exceptions\ValidationFailedException;

trait ThrowsOnValidationFailure
{
    abstract public function failed(): bool;

    abstract public function reason(): string;

    /** @return array<string, mixed> */
    abstract public function context(): array;

    /** @throws ValidationFailedException */
    public function orFail(): void
    {
        if ($this->failed()) {
            throw new ValidationFailedException(
                reason: $this->reason(),
                context: $this->context(),
            );
        }
    }
}
```

The abstract method declarations serve two purposes: PHP will throw a fatal error if a class uses this trait without implementing them, and they self-document the trait's dependencies. When a class both implements `DescribableValidationResult` and uses this trait, the interface and trait requirements overlap completely — no extra work.

The trait works identically for single and aggregate results. For single results, `reason()` and `context()` describe one failure. For aggregate results, `reason()` and `context()` aggregate child failure data. The trait doesn't need to know the difference — it just calls the methods and builds the exception. The formatting intelligence lives in the result class (or the `AggregatesChildResults` trait), not here.

**Override protection:** The codebase requires the `#[Override]` attribute on any method that overrides a parent/trait method. Combined with a linting rule that disallows `#[Override]` on `orFail()` in classes using this trait, this creates a mechanical two-step gate against anyone overriding the enforced implementation.

> **Action: Verify** that the codebase currently enforces `#[Override]` attribute usage before relying on this protection. If not yet enforced, add the rule alongside this validation system.

---

### AggregatesChildResults

The trait for aggregate validation results. Includes `ThrowsOnValidationFailure` internally via trait-within-trait composition, so aggregate result classes only use this single trait. Provides default implementations of `passed()`, `failed()`, `reason()`, and `context()` that loop through child results, and inherits `orFail()` from `ThrowsOnValidationFailure`.

```php
<?php

declare(strict_types=1);

namespace Domain\Shared\Validation\Concerns;

use Domain\Shared\Validation\Contracts\DescribableValidationResult;

trait AggregatesChildResults
{
    use ThrowsOnValidationFailure;

    /** @return array<string, DescribableValidationResult> */
    abstract protected function childResults(): array;

    public function passed(): bool
    {
        foreach ($this->childResults() as $result) {
            if ($result->failed()) {
                return false;
            }
        }

        return true;
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function reason(): string
    {
        $reasons = [];

        foreach ($this->childResults() as $result) {
            if ($result->failed()) {
                $reasons[] = $result->reason();
            }
        }

        return implode('; ', $reasons);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        $context = [];

        foreach ($this->childResults() as $name => $result) {
            if ($result->failed()) {
                $context[$name] = $result->context();
            }
        }

        return $context;
    }
}
```

`AggregatesChildResults` uses `ThrowsOnValidationFailure` internally. The concrete `failed()`, `reason()`, and `context()` methods satisfy `ThrowsOnValidationFailure`'s abstract declarations, and `orFail()` flows through automatically. Aggregate result classes use only this trait — no overlapping method declarations at the class level.

The `childResults()` method is abstract — aggregate result classes must implement it. The string keys in the returned array become the keys in the aggregated `context()` output, providing named identification of each child failure.

---

## Exception

### ValidationFailedException

A single exception class serving all validators — single and aggregate. It is a dumb carrier: it receives a pre-formatted reason string and a pre-built context array. It does not know or care whether its data came from one validator or five.

```php
<?php

declare(strict_types=1);

namespace Domain\Exceptions;

final class ValidationFailedException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly string $reason,
        private readonly array $context = [],
    ) {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
```

`reason` is passed to `parent::__construct()`, so generic exception handlers calling `$e->getMessage()` get the reason string. Handlers that know about `ValidationFailedException` specifically (e.g. a Sentry integration) can also access the structured `context()`.

The exception is `final` — if a specific domain area needs a more specific exception, it defines a new exception class rather than subclassing this one.

All formatting and aggregation happens in the result class before the exception is constructed. The exception simply carries what it's given. This means a single exception class serves all validators — single and aggregate — without any conditional logic. Callers always catch one type.

---

## Validator Construction

Validators receive their domain objects via the constructor. `validate()` takes no arguments. This enables:

- **A shared interface** — every validator has the same `validate(): DescribableValidationResult` signature
- **Composability** — aggregates can call `validate()` on children generically
- **Short-lived objects** — validators are constructed per-invocation with specific data, not registered as singleton services in the DI container

```php
// UseCase constructs validators with runtime domain data
$validator = new MoneyComparisonValidator(
    expected: $expectedPrice,
    actual: $actualPrice,
);

$result = $validator->validate();
```

Since validators only receive domain value objects (not services), no DI container involvement is needed. The UseCase — which knows what data to validate — constructs the validators directly.

For Application-layer validators that need infrastructure dependencies (e.g., a repository), see the **Application-Layer Validators** section below.

---

## Single Validator Examples

### Money Comparison — Multiple Failure Modes

A validator with multiple failure modes and a rich result object.

#### Validator

```php
<?php

declare(strict_types=1);

namespace Domain\Shared\Money\Validators;

use Domain\Shared\Money\Money;
use Domain\Shared\Validation\Contracts\Validator;

final class MoneyComparisonValidator implements Validator
{
    public function __construct(
        private readonly Money $expected,
        private readonly Money $actual,
    ) {}

    public function validate(): MoneyComparisonResult
    {
        $mismatches = [];

        if (! $this->expected->defaultValue()->equals($this->actual->defaultValue())) {
            $mismatches[] = MoneyMismatchField::DefaultValue;
        }

        if ($this->expected->taxType() !== $this->actual->taxType()) {
            $mismatches[] = MoneyMismatchField::TaxType;
        }

        if ($this->expected->currency() !== $this->actual->currency()) {
            $mismatches[] = MoneyMismatchField::Currency;
        }

        return new MoneyComparisonResult($mismatches);
    }
}
```

#### Result

```php
<?php

declare(strict_types=1);

namespace Domain\Shared\Money\Validators;

use Domain\Shared\Validation\Contracts\DescribableValidationResult;
use Domain\Shared\Validation\Concerns\ThrowsOnValidationFailure;

final class MoneyComparisonResult implements DescribableValidationResult
{
    use ThrowsOnValidationFailure;

    /**
     * @param  array<int, MoneyMismatchField>  $mismatches
     */
    public function __construct(
        private readonly array $mismatches = [],
    ) {}

    public function passed(): bool
    {
        return $this->mismatches === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function reason(): string
    {
        $fields = array_map(
            static fn (MoneyMismatchField $field): string => $field->value,
            $this->mismatches,
        );

        return 'Money comparison failed on: ' . implode(', ', $fields);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['mismatched_fields' => $this->mismatches];
    }

    /**
     * Domain-specific accessor for callers that need the detailed failure data.
     *
     * @return array<int, MoneyMismatchField>
     */
    public function mismatches(): array
    {
        return $this->mismatches;
    }
}
```

#### Consumption

```php
// Soft path — caller decides what to do
$result = (new MoneyComparisonValidator(
    expected: $expectedPrice,
    actual: $actualPrice,
))->validate();

if ($result->failed()) {
    $mismatches = $result->mismatches();
    // Handle as appropriate for this UseCase
}

// Strict path — one-liner, throws if failed
(new MoneyComparisonValidator(
    expected: $expectedPrice,
    actual: $actualPrice,
))->validate()->orFail();
```

---

### SKU Belongs to Product — Simple Check

A validator with one failure mode. Demonstrates what a simple check looks like under the unified interface — the result class is lightweight but still provides `reason()` and `context()` for consistency.

#### Validator

```php
<?php

declare(strict_types=1);

namespace Domain\Platform\Product\Validators;

use Domain\Platform\Product\Product;
use Domain\Platform\Product\ValueObjects\Sku;
use Domain\Shared\Validation\Contracts\Validator;

final class SkuBelongsToProductValidator implements Validator
{
    /**
     * @param  array<int, Sku>  $requiredSkus
     */
    public function __construct(
        private readonly Product $product,
        private readonly array $requiredSkus,
    ) {}

    public function validate(): SkuValidationResult
    {
        $productSkus = $this->product->skus();

        $missingSkus = array_values(
            array_filter(
                $this->requiredSkus,
                static fn (Sku $sku): bool => ! $productSkus->contains($sku),
            ),
        );

        return new SkuValidationResult($missingSkus);
    }
}
```

#### Result

```php
<?php

declare(strict_types=1);

namespace Domain\Platform\Product\Validators;

use Domain\Platform\Product\ValueObjects\Sku;
use Domain\Shared\Validation\Contracts\DescribableValidationResult;
use Domain\Shared\Validation\Concerns\ThrowsOnValidationFailure;

final class SkuValidationResult implements DescribableValidationResult
{
    use ThrowsOnValidationFailure;

    /**
     * @param  array<int, Sku>  $missingSkus
     */
    public function __construct(
        private readonly array $missingSkus = [],
    ) {}

    public function passed(): bool
    {
        return $this->missingSkus === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function reason(): string
    {
        return 'SKU validation failed: ' . count($this->missingSkus) . ' missing';
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['missing_skus' => $this->missingSkus];
    }

    /**
     * Domain-specific accessor for callers that need the missing SKU list.
     *
     * @return array<int, Sku>
     */
    public function missingSkus(): array
    {
        return $this->missingSkus;
    }
}
```

#### Consumption

```php
// Soft path
$result = (new SkuBelongsToProductValidator(
    product: $product,
    requiredSkus: $skus,
))->validate();

if ($result->failed()) {
    $missing = $result->missingSkus();
    // Handle — e.g., log and continue, or build a custom exception
}

// Strict path
(new SkuBelongsToProductValidator(
    product: $product,
    requiredSkus: $skus,
))->validate()->orFail();
```

---

## Aggregate Validator Example

An aggregate validator calls multiple child validators and returns an aggregate result. It implements the `Validator` interface — making it fully nestable as a child of another aggregate. Aggregate validators are distinguished by the `AggregateValidator` naming suffix, enabling linting rules specific to this pattern.

### Validator

```php
<?php

declare(strict_types=1);

namespace Domain\Platform\Order\Validators;

use Domain\Platform\Product\Validators\SkuBelongsToProductValidator;
use Domain\Shared\Money\Validators\MoneyComparisonValidator;
use Domain\Shared\Validation\Contracts\Validator;

final class CreateOrderAggregateValidator implements Validator
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

The aggregate's `validate()` calls each child and passes the results to the aggregate result. This example runs all children exhaustively. Short-circuit behaviour (stopping on first failure) can be added later as an internal change without affecting any contracts.

### Aggregate Result

The aggregate result implements `DescribableValidationResult` and uses `AggregatesChildResults` — a single trait that includes `ThrowsOnValidationFailure` internally. The aggregate result only needs to define `childResults()` and domain-specific accessors.

```php
<?php

declare(strict_types=1);

namespace Domain\Platform\Order\Validators;

use Domain\Shared\Validation\Contracts\DescribableValidationResult;
use Domain\Shared\Validation\Concerns\AggregatesChildResults;
use Domain\Shared\Money\Validators\MoneyComparisonResult;
use Domain\Platform\Product\Validators\SkuValidationResult;

final class CreateOrderValidationResult implements DescribableValidationResult
{
    use AggregatesChildResults;

    public function __construct(
        private readonly SkuValidationResult $skuResult,
        private readonly MoneyComparisonResult $priceResult,
    ) {}

    /** @return array<string, DescribableValidationResult> */
    protected function childResults(): array
    {
        return [
            'sku_validation' => $this->skuResult,
            'price_validation' => $this->priceResult,
        ];
    }

    // Domain-specific accessors for callers that need individual child results

    public function skuResult(): SkuValidationResult
    {
        return $this->skuResult;
    }

    public function priceResult(): MoneyComparisonResult
    {
        return $this->priceResult;
    }
}
```

**How the trait works:** `AggregatesChildResults` includes `ThrowsOnValidationFailure` internally via trait composition. The concrete `passed()`, `failed()`, `reason()`, and `context()` methods satisfy `ThrowsOnValidationFailure`'s abstract declarations, and `orFail()` flows through automatically. The aggregate result class uses one trait — no overlapping method declarations, no implicit resolution.

**How the formatting works:** Each child result has its own `reason()` and `context()`. The `AggregatesChildResults` trait loops through failed children, joins their reasons with `'; '`, and nests their contexts under the keys from `childResults()`. A single-child failure produces a single reason string. Multiple failures produce a semicolon-separated aggregate.

When `orFail()` fires, it passes the aggregated `reason()` and `context()` to `ValidationFailedException`. The exception receives a pre-formatted string and pre-built structured data. It doesn't know it came from an aggregate.

### Consumption

```php
// UseCase constructs the full validator tree
$validator = new CreateOrderAggregateValidator(
    skuValidator: new SkuBelongsToProductValidator(
        product: $product,
        requiredSkus: $requiredSkus,
    ),
    priceValidator: new MoneyComparisonValidator(
        expected: $expectedPrice,
        actual: $actualPrice,
    ),
);

// Soft path
$result = $validator->validate();

if ($result->failed()) {
    // Access aggregate
    $reason = $result->reason();
    // e.g. "SKU validation failed: 3 missing; Money comparison failed on: currency"

    // Or access individual child results
    if ($result->skuResult()->failed()) {
        $missing = $result->skuResult()->missingSkus();
    }
}

// Strict path — one-liner
$validator->validate()->orFail();
// Throws ValidationFailedException with aggregated reason and context
```

Because aggregate results implement the same interface as single results, aggregates can nest inside other aggregates. A higher-level aggregate treats a lower-level aggregate as just another child.

---

## Application-Layer Validators

Domain validators only receive domain value objects — no infrastructure. Application-layer validators need infrastructure dependencies (e.g., a repository to check "does this SKU exist in the database?").

The UseCase — which Laravel resolves via DI — already has the infrastructure dependencies it needs. It constructs the Application validator manually, passing both services and runtime data:

```php
// In a UseCase (resolved by Laravel's container)
final class ProcessOrderUseCase
{
    public function __construct(
        private readonly SkuRepository $skuRepository, // Injected by DI
    ) {}

    public function execute(Order $order): void
    {
        // Manually construct the Application validator
        $validator = new SkuExistsInDatabaseValidator(
            repository: $this->skuRepository,
            skus: $order->skus(),
        );

        $validator->validate()->orFail();

        // ... continue with order processing
    }
}
```

The Application validator itself follows the same contract — `implements Validator`, parameterless `validate()`, returns `DescribableValidationResult`. The only difference is it receives a service alongside domain data in its constructor:

```php
// Application/{Context}/Validators/SkuExistsInDatabaseValidator.php
final class SkuExistsInDatabaseValidator implements Validator
{
    /** @param  array<int, Sku>  $skus */
    public function __construct(
        private readonly SkuRepository $repository,
        private readonly array $skus,
    ) {}

    public function validate(): SkuExistsResult
    {
        // ... check each SKU against the repository
    }
}
```

No DI container involvement on the validator itself. No private constructors or static factories needed. Laravel handles the DI at the UseCase level, and the UseCase passes what the validator needs.

---

## Linting Rules

### 1. Validator Placement

Classes whose name ends with `Validator` under the `Domain` namespace must reside in a namespace segment containing `Validators`.

**Effect:** Prevents validators scattering outside their designated directories.

### 2. Validator Directory Contents

Classes in any `Domain\**\Validators\` namespace must have names ending in `Validator` or `Result`.

**Effect:** Keeps the Validators directory focused — no unrelated classes creep in. Supporting types like enums (e.g., `MoneyMismatchField`) live with their parent domain concept, not in the Validators directory.

### 3. Validate Method

Classes in any `Domain\**\Validators\` namespace whose name ends in `Validator` must have a public `validate()` method.

**Effect:** Prevents structural inconsistency where a class sits in a Validators directory but doesn't follow the validator contract. This is a linting rule because the `Validator` interface enforces the method signature — this rule catches classes that forget to implement the interface entirely.

### 4. Trait Enforcement — Single Validators

Result classes implementing `DescribableValidationResult` that are NOT aggregate results must use the `ThrowsOnValidationFailure` trait.

**Effect:** Prevents bespoke `orFail()` implementations. Every single result uses the same mechanical path to throw exceptions.

> **Implementation note:** PHPArkitect may not support "implementors of interface X must use trait Y" natively. If not, this rule should be implemented as either a custom PHPStan rule or a Pest architecture test (`arch()->expect(...)->toUseTrait(...)`). Spike this before committing to a specific enforcement tool.

### 5. Aggregate Validator Naming and Trait Enforcement

Classes whose name ends with `AggregateValidator` must use the `AggregatesChildResults` trait (which includes `ThrowsOnValidationFailure` internally).

**Effect:** The naming convention is the linting signal. Any aggregate validator is mechanically forced to use the correct trait, which provides consistent aggregation logic and `orFail()` behaviour. This prevents hand-rolled aggregation drifting across aggregates.

### 6. Override Protection

The `#[Override]` attribute is required on any method overriding a trait method. Combined with a rule disallowing `#[Override]` on `orFail()`, this prevents any class from silently replacing the trait's implementation.

> **Action: Verify** that the codebase currently enforces `#[Override]` attribute usage. If not yet enforced, add the rule alongside this validation system.

---

## Enforcement Summary

| Concern | Enforced by | Mechanism |
|---|---|---|
| Interface contracts (methods exist, types correct) | PHPStan | Static analysis at level max |
| Trait abstract methods satisfied | PHP + PHPStan | Fatal error at runtime + static analysis |
| Validators in correct directories | PHPArkitect | Namespace/naming rules |
| Validators have validate() method | PHPArkitect | Method existence rule |
| Consistent `orFail()` — single validators | PHPArkitect / custom PHPStan rule / Pest arch test | Trait usage enforcement (see note above) |
| Consistent aggregation + `orFail()` — aggregates | PHPArkitect / Pest arch test | `AggregateValidator` naming → `AggregatesChildResults` trait |
| No override of trait's orFail() | `#[Override]` attribute + linting | Two-step gate (verify codebase support) |
| Test coverage on validators | Existing test rules | Directory-based coverage targets |
| Exception carries reason + context to Sentry | Exception pipeline | `ValidationFailedException` structure |

No enforcement relies on code review discipline. Every constraint is mechanically checked.

---

## Design Decisions

**Why a single interface instead of two?** Every validator that can fail should be able to describe its failure. A boolean validator that can't describe why it failed is just deferring that work to every caller individually — which is exactly the inconsistency this system prevents. One interface means no tier-selection decision, no ambiguity, and aggregates can treat all child results uniformly.

**Why parameterless `validate()` with constructor injection?** This enables a shared validator interface (`validate(): DescribableValidationResult`) and makes aggregation possible — the aggregate can call `validate()` on any child without knowing its parameters. Since validators only receive domain value objects (not services), constructor injection is natural and no DI container is needed.

**Why is the exception a dumb carrier?** `reason()` and `context()` exist on the result interface for the **soft path** — callers who inspect the result without throwing. This aggregation logic must live on the result regardless of what the exception does. Moving it to the exception would duplicate it (once on the result for soft path, once on the exception for strict path). Instead, the exception receives pre-formatted data from the result. One source of truth, no duplication.

**Why an `AggregatesChildResults` trait instead of aggregation in the exception?** The aggregate result's `reason()` and `context()` must aggregate child data for the soft path (when `orFail()` isn't called). This aggregation can't live in the exception because the exception only exists on the strict path. The trait centralises the aggregation logic so it's written once, and every aggregate result gets it for free.

**Why don't aggregate results need changes to `ThrowsOnValidationFailure` or the exception?** `AggregatesChildResults` includes `ThrowsOnValidationFailure` internally via trait composition. The aggregate result's `reason()` and `context()` return aggregated strings and structured arrays. `orFail()` calls these methods to build the exception — it doesn't know or care whether the data came from one failure or five. The exception receives a pre-formatted string and structured array. No hooks, no child-exception arrays, no special aggregate logic in the base infrastructure.

**Why distinguish aggregate validators with naming (`AggregateValidator`)?** Aggregate validators are structurally different from single validators — they orchestrate other validators rather than validating domain objects directly, and they use a different trait (`AggregatesChildResults` vs `ThrowsOnValidationFailure`). The naming suffix is the linting signal that enforces the correct trait, following the same "name is the gate" pattern used throughout the system.

**Why a trait instead of an abstract class for `orFail()`?** The trait provides exactly one method (`orFail`) without imposing an inheritance hierarchy. Result classes stay `final` and can compose freely. An abstract class would force a single inheritance chain that constrains future result designs.

**Does this design break Liskov Substitution Principle?** No. Covariant return types (e.g., `MoneyComparisonResult` where `DescribableValidationResult` is expected) are explicitly LSP-compliant. Aggregates implementing the same interface as single validators is textbook substitutability. All implementations satisfy the interface contract without surprising behaviour.

**Why co-locate results with validators?** The result is an output type of the validator — they're always used together. Putting them in the same directory keeps related code discoverable without adding directory depth. Supporting types like enums live with their parent domain concept, not in the Validators directory.

**Why define the interface upfront rather than letting it emerge?** Validation is a cross-cutting concern that touches Domain, Application, Presentation, and exception handling. Without a contract, independent implementations drift into inconsistency. The interface costs almost nothing to define and prevents expensive retrofitting. Constraints are cheapest to impose before things proliferate.

**Where do aggregate validators live?** With the domain concept that owns the process they validate. An order creation aggregate lives with Order, even though it calls validators from Product and Money. Cross-domain references within Domain are allowed.

**Where do validators that need infrastructure live?** In Application. The UseCase injects the infrastructure dependency via its own DI-resolved constructor and passes it to the validator manually. The validator still implements the same `Validator` interface and returns `DescribableValidationResult`. No special DI configuration, no static factories.

**What about static factories on validators?** For this design, they're an aesthetic preference, not a structural necessity. Construction is trivially passing domain objects to a constructor. If a specific validator's construction reads confusingly without a named factory method, add one selectively — but don't apply the pattern across all validators by default.

**What is the audience for `reason()`?** Developer/ops observability — logs, Sentry, exception messages. It is NOT user-facing. If Presentation needs user-facing validation messages, that's a Presentation-layer responsibility that reads `context()` and builds its own messaging. Do not put user-facing copy into `reason()`.

---

## Deferred Decisions

**Short-circuit for aggregates.** The aggregate example runs all children exhaustively. Short-circuit behaviour (stopping when a child fails because subsequent children depend on it passing) can be added as an aggregate-internal change. It does not affect any contracts, interfaces, or the exception design. Address when the need arises.

---

## Migration

The existing codebase has ad-hoc validation scattered across UseCases (manual checks + inline exception throws) alongside Webmozart assertions in VO constructors. These serve different purposes and should not be conflated during migration:

- **Webmozart assertions in VO constructors** → Stay as-is. These enforce construction-time invariants ("this value cannot be negative"). They are programming error guards, not business process validation.
- **Ad-hoc UseCase checks** → Classify and migrate. Each needs sorting into: invariant enforcement (convert to Webmozart assertion if not already) or business process validation (migrate to the new validator system).

> **Action: Create a GitHub issue** to audit existing UseCase validation. Walk through each ad-hoc check and classify it as either an invariant (Webmozart) or a process precondition (new validator system). This review should happen after the first 2-3 validators are built using the new system, so the patterns are established and the migration target is concrete.
