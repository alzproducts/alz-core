# When to Mock

Mock at **the outermost boundary you cannot test through**:

- External APIs (payment gateways, third-party services, etc.)
- Databases when you don't have a test DB available
- Time/randomness
- File system (sometimes)

## The Boundary Rule

The core question is: *what is the outermost boundary for this test?*

In flat architectures (frontend, simple scripts), the boundary is the external API — everything between your entry point and that API is "your code" and should be tested through, not mocked.

In **layered architectures** (Clean Architecture, DDD, hexagonal), each layer boundary is an intentional seam enforced by architecture rules. The layer interface IS the boundary. Mocking at that seam tests the contract, not the wiring.

**In a Clean Architecture PHP codebase:**

```
Presentation → [Application interface] → Application → [Domain interface] → Infrastructure
```

When testing an Application UseCase:
- **Mock** the interfaces it depends on (e.g., `PaymentClientInterface`, `OrderRepositoryInterface`) — these are your boundaries
- **Do not mock** concrete Infrastructure classes directly — test through the interface
- **Do not mock** other Application classes that belong to the same layer

```php
// CORRECT — mocking at the layer boundary (interface in Application/Contracts)
$paymentClient = Mockery::mock(PaymentClientInterface::class);
$paymentClient->shouldReceive('charge')->once()->with($order)->andReturn($receipt);

$useCase = new ProcessOrderUseCase($paymentClient, $orderRepo);
$result = $useCase->execute($command);

// WRONG — mocking a concrete Infrastructure class
$paymentClient = Mockery::mock(StripePaymentClient::class);
```

The interface is the contract the Application layer owns and depends on. Mocking it tests that the UseCase uses the contract correctly — that is behavioral testing.

## Dependency Injection

Testable PHP classes accept dependencies in their constructor rather than instantiating them internally:

```php
// Easy to mock — dependency injected
class ProcessOrderUseCase
{
    public function __construct(
        private readonly PaymentClientInterface $paymentClient,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}
}

// Hard to mock — dependency created internally
class ProcessOrderUseCase
{
    public function execute(Order $order): void
    {
        $client = new StripeClient(config('services.stripe.key')); // can't substitute in tests
        $client->charge($order);
    }
}
```

In a PHP framework (Laravel, Symfony), constructor injection is handled by the service container — you declare the interface, the container resolves the concrete implementation in production and you supply a mock in tests.

## Prefer Specific Interfaces Over Generic Ones

Design interfaces with specific methods per operation rather than one generic method:

```php
// GOOD: Each method is independently mockable with a specific return shape
interface InventoryClientInterface
{
    public function updateJit(Sku $sku, bool $value): void;
    public function updateMinimumLevel(Sku $sku, int $level): void;
    public function getStockLevel(Sku $sku): StockLevel;
}

// BAD: Mocking requires conditional logic to handle all cases
interface InventoryClientInterface
{
    public function call(string $operation, array $params): mixed;
}
```

Specific interfaces mean:
- Each mock expectation returns one known shape
- No conditional logic in test setup
- Tests clearly document which operations a UseCase exercises
