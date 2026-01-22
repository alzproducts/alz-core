# Presentation Layer Exception Handling

## Purpose
Presentation catches **only for delivery mechanism**: queue retry, HTTP responses, console output. This is "the Laravel stuff" - framework integration.

## Queue Priority Tiers

| Queue | Timeout | Use Case |
|-------|---------|----------|
| `high` | 90s | Time-sensitive, user-facing (webhooks, notifications) |
| `default` | 90s | Normal priority (order sync, daily jobs) |
| `low` | 3600s | Bulk/background work (full customer sync, data migrations) |

Route jobs via constructor: `$this->onQueue('low')`. Config: `config/horizon.php`.

## Jobs: Queue Retry Management

### Default: Let Laravel Handle It
```php
class SyncGoogleAdsToMixpanelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 300;
    
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600]; // Exponential backoff
    }

    public function handle(SyncAdSpendUseCase $useCase): void
    {
        // No try-catch - Laravel retries automatically
        $useCase->execute($this->date ?? now()->subDay()->format('Y-m-d'));
    }
    
    public function failed(?Throwable $exception): void
    {
        Log::critical('Sync permanently failed', [
            'exception' => $exception?->getMessage(),
        ]);
        
        Mail::to('[email protected]')->send(new JobFailedNotification($exception));
    }
}
```

### When to Catch: Custom Retry Logic
```php
class SyncGoogleAdsToMixpanelJob implements ShouldQueue
{
    public int $tries = 5;
    
    public function handle(SyncAdSpendUseCase $useCase): void
    {
        try {
            $useCase->execute($this->date);
            
        } catch (InvalidApiResponseException $e) {
            // Programming error - don't waste retries
            Log::critical('API contract violation', [
                'service' => $e->serviceName,
                'action' => 'CODE_UPDATE_REQUIRED',
            ]);
            $this->fail($e); // Fail immediately
            
        } catch (ExternalServiceUnavailableException $e) {
            // Transient failure - use API's retry-after
            Log::warning('Service unavailable', ['retry_after' => $e->retryAfter]);
            $this->release($e->retryAfter);
            
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - don't waste retries
            Log::critical('Auth expired', ['service' => $e->serviceName]);
            $this->fail($e);
        }
        // Other exceptions bubble - Laravel retries automatically
    }
}
```

**Catch jobs when:**
- API contract violations (fail immediately - code needs fixing)
- Custom retry delay (use API's Retry-After)
- Permanent failures (auth expired - don't retry)

### Required: Final Throwable Catch

All jobs MUST end with `catch (\Throwable)` that:
1. Logs critical (unexpected exception = code needs updating)
2. Calls `$this->fail($e)` for standard jobs (skip for business-critical jobs that should retry)
3. Rethrows with `throw $e` (safe - Laravel checks `hasFailed()` to prevent double-processing)

See existing jobs in `app/Presentation/Jobs/` for implementation examples.

## Controllers: Global Exception Handler (Preferred)
```php
// bootstrap/app.php
return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            return response()->json([
                'error' => 'insufficient_stock',
                'message' => $e->getMessage(),
            ], 400);
        });
        
        $exceptions->render(function (ExternalServiceUnavailableException $e, Request $request) {
            return response()->json([
                'error' => 'service_unavailable',
                'retry_after' => $e->retryAfter,
            ], 503);
        });
        
        $exceptions->render(function (AuthenticationExpiredException $e, Request $request) {
            return response()->json([
                'error' => 'authentication_required',
            ], 401);
        });
    })
    ->create();
```

**Controllers stay clean:**
```php
class OrderController extends Controller
{
    public function store(CreateOrderRequest $request, CreateOrderUseCase $useCase)
    {
        // No try-catch - global handler converts exceptions to HTTP
        $order = $useCase->execute($request->validated());
        return response()->json($order, 201);
    }
}
```

## Commands: User-Friendly Output
```php
class SyncAdSpendCommand extends Command
{
    protected $signature = 'adspend:sync {--date=}';

    public function handle(SyncAdSpendUseCase $useCase): int
    {
        $date = $this->option('date') ?? now()->subDay()->format('Y-m-d');
        
        try {
            $this->info("Syncing ad spend for {$date}...");
            $useCase->execute($date);
            $this->info('✓ Sync completed');
            return self::SUCCESS;
            
        } catch (ExternalServiceUnavailableException $e) {
            $this->error("✗ {$e->serviceName} unavailable");
            $this->warn("  Retry in {$e->retryAfter}s");
            return self::FAILURE;
            
        } catch (AuthenticationExpiredException $e) {
            $this->error("✗ Auth expired for {$e->serviceName}");
            $this->warn('  Update credentials and retry');
            return self::FAILURE;
        }
    }
}
```

**Catch commands for:**
- User-friendly error messages
- Appropriate exit codes
- Guide user toward resolution

## Anti-Patterns

### ❌ Don't Catch Just to Log
```php
// WRONG
public function handle(SyncAdSpendUseCase $useCase): void
{
    try {
        $useCase->execute($this->date);
    } catch (\Throwable $e) {
        Log::error('Failed'); // Laravel already logs
        throw $e;
    }
}

// RIGHT
public function handle(SyncAdSpendUseCase $useCase): void
{
    $useCase->execute($this->date);
}
```

### ❌ Don't Duplicate Global Handler
```php
// WRONG: Controller try-catch when global handler exists
public function store(Request $request, CreateOrderUseCase $useCase)
{
    try {
        return $useCase->execute($request->validated());
    } catch (InsufficientStockException $e) {
        return response()->json(['error' => $e->getMessage()], 400);
    }
}

// RIGHT: Let global handler do it
public function store(Request $request, CreateOrderUseCase $useCase)
{
    return $useCase->execute($request->validated());
}
```

## Decision Tree
```
Exception reaches Presentation
    ↓
Is this a Job?
    → Need custom retry delay? → Catch, use $this->release()
    → Permanent failure? → Catch, use $this->fail()
    → Otherwise → Don't catch (Laravel retries automatically)
    
Is this a Controller?
    → Can global handler handle it? → Don't catch
    → Need transaction rollback + redirect? → Catch in controller
    
Is this a Command?
    → Catch for user-friendly output + exit codes
```

## Testing
```php
test('job releases on service unavailable', function () {
    Queue::fake();
    
    $useCase = Mockery::mock(SyncAdSpendUseCase::class);
    $useCase->shouldReceive('execute')
        ->andThrow(new ExternalServiceUnavailableException('Google Ads', 120));
    
    $job = new SyncGoogleAdsToMixpanelJob('2024-11-18');
    $job->handle($useCase);
    
    expect($job->isReleased())->toBeTrue();
});

test('returns 503 when service unavailable', function () {
    $useCase = Mockery::mock(CreateOrderUseCase::class);
    $useCase->shouldReceive('execute')
        ->andThrow(new ExternalServiceUnavailableException('Payment', 60));
    
    $this->app->instance(CreateOrderUseCase::class, $useCase);
    
    $this->postJson('/api/orders', [])
        ->assertStatus(503)
        ->assertJson(['retry_after' => 60]);
});
```

## Checklist

- [ ] Catching for framework integration (retry/HTTP/console)?
- [ ] Does global handler already handle this?
- [ ] Am I catching to control delivery mechanism?
- [ ] Have I avoided catching just to log?
- [ ] Have I avoided business logic in Presentation?

**Golden Rule**: Presentation speaks Laravel to framework, business concepts to users.

---

## Directory Organization

**Feature threshold**: Create subdirectory when feature has 2+ related files.

| Location | Contents |
|----------|----------|
| `Http/{Feature}/` | Feature-specific middleware, resources |
| `Http/Middleware/` | Global-only middleware |
| `Http/Controllers/{Feature}/` | Feature controllers |
| `Jobs/{Integration}/` | Integration-specific jobs |

## Naming

**Jobs**: `Sync*` (data sync), `Process*` (transform), `Reconcile*` (compare/fix)

**Controllers**: Multi-action `{Feature}Controller`, single-action invokable (`__invoke`)
