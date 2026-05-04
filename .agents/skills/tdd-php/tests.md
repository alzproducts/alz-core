# Good and Bad Tests

## Good Tests

**Behavior-focused**: Test what the system does through its public interface, not how it does it internally.

```php
// GOOD: Tests observable behavior — the UseCase's orchestration decision
#[Test]
public function it_partitions_failures_by_exception_type(): void
{
    $this->apiClient->shouldReceive('update')
        ->once()
        ->andThrow(new ResourceNotFoundException('service', 'Item', 'SKU-1'));

    $result = $this->useCase->execute([$command]);

    $this->assertSame(1, $result->total);
    $this->assertSame(0, $result->succeeded);
    $this->assertSame(['SKU-1'], array_column($result->permanentFailures, 'identifier'));
}
```

Characteristics:

- Tests behavior callers care about
- Uses the public `execute()` method only
- Survives internal refactors — you could rewrite the exception-handling logic entirely and this test still defines the contract
- Describes WHAT, not HOW
- Specific assertions on real values, not just truthiness

## Asserting on Call Order and Arguments

Asserting that a method was called with specific arguments or in a specific order is **only a red flag when there's a higher-level behavioral boundary you could test through instead.**

In a layered architecture, the UseCase's public boundary IS `execute()`. Cross-layer calls (to external APIs, repositories) are the UseCase's observable side effects — they ARE the behavior. Verifying them is not implementation coupling.

```php
// CORRECT — call order is a business requirement
// "Write to external API before updating local DB" is not internal wiring, it's the spec.
$this->apiClient->shouldReceive('update')
    ->ordered()
    ->once()
    ->withArgs(fn(Sku $sku): bool => $sku->value === 'SKU-1');

$this->repository->shouldReceive('bulkWrite')
    ->ordered()
    ->once();

$this->useCase->execute($commands);
```

The red flag applies when you're asserting on internal method calls that don't cross a layer boundary:

```php
// BAD — asserting on a private helper within the same class/layer
// If you rename buildPayload() the test breaks but behavior didn't change
$this->useCase->shouldReceive('buildPayload')->once(); // ← testing internals
```

## Bad Tests

**Weak assertions**: Technically pass but prove nothing.

```php
// BAD: Passes even if $result is completely wrong
$this->assertNotNull($result);
$this->assertTrue($result->succeeded >= 0);

// GOOD: Specifies the actual expected value
$this->assertSame(3, $result->succeeded);
$this->assertSame([], $result->permanentFailures);
```

Weak assertions are the most common failure in AI-generated tests. They produce 100% coverage with 0% confidence. Mutation testing catches these — if changing `===` to `!==` in the implementation doesn't fail your test, the assertion is too loose.

**Mocking concrete classes instead of interfaces**:

```php
// BAD: Mocks the concrete Eloquent implementation
// Test now depends on the internal class name — breaks if you swap implementations
$repo = Mockery::mock(EloquentOrderRepository::class);

// GOOD: Mocks the interface (the contract the Application layer owns)
$repo = Mockery::mock(OrderRepositoryInterface::class);
```

**Bypassing the interface to verify state** (integration tests):

In integration tests where the repository is a real implementation, verify through the interface rather than poking at storage directly. In unit tests with a mocked repository, the equivalent principle is to assert on what was passed to the mock's write method — verification happens through orchestration assertions, not by reading state back.

```php
// BAD: Bypasses the interface to inspect database state directly
public function it_saves_the_order(): void
{
    $this->useCase->execute($command);
    $row = DB::table('orders')->where('id', $command->id)->first();
    $this->assertNotNull($row); // also a weak assertion
}

// GOOD: Verifies through the interface
public function it_makes_the_order_retrievable(): void
{
    $this->useCase->execute($command);
    $order = $this->orderRepository->findById($command->id);
    $this->assertSame($command->id, $order->id->value);
}
```

**Testing what static analysis already guarantees**:

```php
// BAD: PHPStan already knows execute() returns BatchResult
// This test adds ceremony with no confidence
$result = $this->useCase->execute($commands);
$this->assertInstanceOf(BatchResult::class, $result);
```

If PHPStan Level max + strict types is enforced, don't write tests to verify type contracts the type system already guarantees. Test the *values* inside the types.
