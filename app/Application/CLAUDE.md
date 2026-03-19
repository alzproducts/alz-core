# Application Layer

## Directory Structure for New Integrations

When adding a new external service integration (e.g., Google Ads, QuickBooks), follow this template:

```
[IntegrationName]/
├── Services/                   # Caching decorators, business logic with state
├── UseCases/                   # Entry points (orchestration, thin)
├── Queries/                    # Complex read params (optional, when needed)
├── Commands/                   # Write operations (optional, when needed)
└── Results/                    # Operation outcome objects (optional)
```

**When to use each:**
- **Services/**: Caching wrappers, stateful business logic, cross-concern coordination
- **UseCases/**: Single entry point per operation, orchestrates Services, stays thin
- **Queries/**: Parameter objects for complex read operations (e.g., `ConversationQueryParams`)
- **Commands/**: Parameter objects for write operations (future, when needed)
- **Results/**: Operation outcomes with success/failure tracking (e.g., `SyncResult`)

**Contracts**: Define interfaces in `Application/Contracts/[IntegrationName]/` when there are 2+ interfaces for an integration. Single interfaces can stay flat at `Application/Contracts/`.

---

## Jobs (`Application/Jobs/`)

Jobs live in the Application layer (not Presentation) because they orchestrate business logic and aren't tied to a specific delivery mechanism. They are in a Deptrac/PHPArkitect sub-layer (`ApplicationJobs`) with explicit Laravel framework access.

### Queue Priority Tiers

| Queue | Timeout | Use Case |
|-------|---------|----------|
| `high` | 90s | Time-sensitive, user-facing (webhooks, notifications) |
| `default` | 90s | Normal priority (order sync, daily jobs) |
| `low` | 3600s | Bulk/background work (full customer sync, data migrations) |

Route jobs via constructor: `$this->onQueue('low')`. Config: `config/horizon.php`.

### Required Job Properties

Every job must define: `$tries`, `$timeout`, `backoff()` (property or method), `failed()` method, and call `$this->onQueue()` in the constructor. These are enforced by custom PHPStan rules in `DevTools/PHPStan/Rules/Jobs/`.

### Naming Convention

Job class names must start with: `Sync`, `Process`, `Reconcile`, `Set`, `Update`, or `Cleanup`.

---

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

---

## Complex Use Case Reference

**`Shopwired/PricingUpdate/`** — Multi-phase batch orchestration pattern:

- Typed result objects (`SkippedPriceUpdateResult`, `FailedPriceUpdateResult`) over array shapes
- Phase-scoped results per step, merged via `PriceUpdateResult::fromPhases()` factory
- Single-item validation extracted with union return type, sorted by `match(true)` on `instanceof`
- `execute()` stays a thin 5-step pipeline delegating to focused private methods
