# Application Layer Exception Handling

## Logging Decision

**PSR-3 `LoggerInterface` accepted in Application layer** - Log business events only (workflow milestones, coordination), not technical details. PSR-3 is a stable PHP-FIG interface, provides observability value for distributed workflows.

## Interface Placement Rules

**Core Principle:** Interfaces live where they're USED, not where they're IMPLEMENTED.

- Application defines cross-layer contracts: `Application/Contracts/MixpanelClientInterface`
- Infrastructure implements: `MixpanelClient implements MixpanelClientInterface`
- Infrastructure may have internal-only interfaces (not crossing layer boundaries)
- Cross-layer interfaces in `/Contracts/` subdirectories within Domain or Application

**Why:** Dependency Inversion Principle — higher layers define contracts, lower layers fulfill them.

## Purpose
Application layer **rarely catches exceptions**. It orchestrates Domain logic and lets exceptions bubble to Presentation. Only catch when business coordination requires it.

## Default Pattern: Don't Catch
```php
// ✅ CORRECT: Let exceptions bubble
class SyncAdSpendUseCase
{
    public function execute(string $date): void
    {
        // No try-catch - exceptions flow naturally
        $campaigns = $this->googleAds->getDailyCampaignMetrics($date);
        
        if ($campaigns === []) {
            Log::info('No campaigns found', ['date' => $date]);
            return;
        }
        
        $events = $this->transformer->transformToEvents($campaigns);
        $this->mixpanel->importBatch($events);
    }
}
```

**Why no try-catch?**
- Infrastructure already logged technical details
- Presentation will decide how to handle (retry, fail, respond)
- Adding try-catch duplicates logging with no value

## When Application SHOULD Catch

### Case 1: Batch Processing
**Business rule: "Process all dates, continue on failures"**
```php
class SyncMultipleDatesUseCase
{
    public function execute(array $dates): array
    {
        $results = [];
        
        foreach ($dates as $date) {
            try {
                $this->syncSingleDate->execute($date);
                $results[$date] = 'success';
                
            } catch (ExternalServiceUnavailableException $e) {
                Log::warning('Date sync failed', ['date' => $date]);
                $results[$date] = 'failed';
            }
        }
        
        return $results;
    }
}
```

### Case 2: Transaction Coordination
```php
class CreateOrderUseCase
{
    public function execute(CreateOrderDTO $dto): Order
    {
        DB::beginTransaction();
        
        try {
            $order = $this->orders->create($dto);
            $this->inventory->reserve($dto->items);
            $this->payments->charge($dto->paymentMethod, $order->total);
            
            DB::commit();
            return $order;
            
        } catch (InsufficientStockException $e) {
            DB::rollBack();
            throw $e;
            
        } catch (PaymentFailedException $e) {
            DB::rollBack();
            $this->inventory->release($dto->items); // Cleanup
            throw $e;
        }
    }
}
```

### Case 3: Context Transformation
```php
class FulfillOrderUseCase
{
    public function execute(int $orderId): void
    {
        try {
            $this->shippingService->createShipment($orderId);
        } catch (ExternalServiceUnavailableException $e) {
            // Transform infrastructure → business context
            throw new OrderCannotBeFulfilledException(
                $orderId,
                "Shipping unavailable, retry in {$e->retryAfter}s"
            );
        }
    }
}
```

## Anti-Patterns

### ❌ Don't Catch Just to Log
```php
// WRONG
public function execute(string $date): void
{
    try {
        $campaigns = $this->googleAds->getDailyCampaignMetrics($date);
    } catch (ExternalServiceUnavailableException $e) {
        Log::error('Failed'); // Already logged in Infrastructure
        throw $e;
    }
}

// RIGHT
public function execute(string $date): void
{
    $campaigns = $this->googleAds->getDailyCampaignMetrics($date);
}
```

### ❌ Don't Wrap in Generic Exceptions
```php
// WRONG
try {
    $campaigns = $this->googleAds->getDailyCampaignMetrics($date);
} catch (ExternalServiceUnavailableException $e) {
    throw new SyncFailedException(); // Loses context
}

// RIGHT
$campaigns = $this->googleAds->getDailyCampaignMetrics($date);
```

## Decision Tree
```
Exception in Use Case
    ↓
Need to handle multiple items where some can fail?
    → YES: Catch, continue processing, return results
    → NO: ↓
    
Need to coordinate transactions/cleanup?
    → YES: Catch specific exceptions, rollback/cleanup, rethrow
    → NO: ↓
    
Need to add business context?
    → YES: Catch, wrap with context, throw business exception
    → NO: DON'T CATCH
```

## Exception Documentation
```php
/**
 * Sync Google Ads data to Mixpanel.
 * 
 * @throws ExternalServiceUnavailableException When APIs unavailable
 * @throws AuthenticationExpiredException When credentials invalid
 */
public function execute(string $date): void
```

## Testing
```php
test('use case bubbles exceptions', function () {
    $googleAds = Mockery::mock(GoogleAdsClientInterface::class);
    $googleAds->shouldReceive('getDailyCampaignMetrics')
        ->andThrow(new ExternalServiceUnavailableException('Google Ads', 60));
    
    $useCase = new SyncAdSpendUseCase($googleAds, ...);
    
    expect(fn() => $useCase->execute('2024-11-18'))
        ->toThrow(ExternalServiceUnavailableException::class);
});
```

## Checklist: Should I Catch?

- [ ] Is this for business coordination? (batch, transactions)
- [ ] Do I need to transform context?
- [ ] Am I doing more than logging and rethrowing?
- [ ] Does business require custom behavior?
- [ ] Can Presentation handle this better? (usually yes)

If "no" to most, **don't catch** - let it bubble.
