# The definitive guide to Laravel queued jobs in 2025–2026

**Laravel's queue system remains the backbone of scalable PHP applications, and the Laravel 11/12 era brings meaningful refinements**: a unified `Queueable` trait, first-class job middleware like `Skip` and `FailOnException`, the `Context` facade for automatic correlation ID propagation, queue pause/resume capabilities, and a failover queue driver. This guide covers everything from job class design to production Horizon tuning to clean architecture patterns — synthesized from official documentation, community leaders (Spatie, Tim MacDonald, Mohamed Said, Loris Leiva), and real-world production experience. The core philosophy hasn't changed: **jobs should be small, idempotent, thin orchestrators** that delegate business logic to dedicated services. What has changed is the tooling to enforce that philosophy.

---

## 1. Job class structure — the foundation

### The modern job skeleton

Laravel 11 introduced a unified `Queueable` trait that replaces the four-trait boilerplate (`Dispatchable`, `InteractsWithQueue`, `Queueable`, `SerializesModels`). New jobs generated via `php artisan make:job` use this streamlined structure:

```php
<?php
namespace App\Jobs;

use App\Models\Podcast;
use App\Services\AudioProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPodcast implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Podcast $podcast,
    ) {}

    public function handle(AudioProcessor $processor): void
    {
        $processor->process($this->podcast);
    }
}
```

The old four-trait pattern still works and requires no migration. The **critical design rule**: constructors receive *data* (serialized onto the queue), while `handle()` receives *services* (resolved from the container at execution time). Never pass closures, file handles, PDO connections, or other non-serializable resources into constructors.

### Single responsibility and thin orchestration

A job should do **one thing**: process a file, send a notification, sync data to an external API. The `handle()` method should read like a table of contents, not a novel. Complex business logic belongs in dedicated Action or Service classes:

```php
// ❌ Job doing too much
public function handle(): void
{
    $data = $this->parseCSV($this->file);
    $this->validateData($data);
    $this->importToDatabase($data);
    $this->sendNotification();
    $this->updateSearchIndex();
}

// ✅ Job as thin orchestrator
public function handle(ImportService $service): void
{
    $service->import($this->file);
}
```

This pattern delivers immediate practical benefits: failed jobs are easier to debug (you know exactly what failed), retry behavior is predictable (the job either completes or doesn't), and business logic remains testable without queue infrastructure.

### Idempotency is non-negotiable

Because Laravel retries failed jobs, **every job must produce the same result whether executed once or five times**. Techniques include using `updateOrCreate()` instead of `create()`, checking whether work was already completed before proceeding, passing idempotency keys to external APIs (Stripe, payment gateways), and relying on database unique constraints as safety nets:

```php
public function handle(): void
{
    if (Payment::where('transaction_id', $this->transactionId)->exists()) {
        return; // Already processed
    }

    $response = Http::withHeaders([
        'Idempotency-Key' => $this->transactionId,
    ])->post('https://payment-api.com/charge', [
        'amount' => $this->amount,
    ]);

    Payment::create([
        'transaction_id' => $response['id'],
        'amount' => $this->amount,
    ]);
}
```

### Properties: serialization pitfalls that bite in production

**Eloquent model serialization** is the single largest source of queue bugs. The `SerializesModels` trait (included in the unified `Queueable` trait) serializes only the model's ID and class name. When the job executes, the model is re-fetched from the database. This creates three pitfalls:

- **Stale data**: The model at execution time may differ from dispatch time. If you need a snapshot, use a DTO.
- **Relationship bloat and lost constraints**: If you loaded `$user->load(['comments' => fn($q) => $q->limit(3)])`, the deserialized model re-fetches *all* comments without the limit. Loaded relationships also inflate the serialized payload.
- **Missing models**: If the model is deleted between dispatch and execution, a `ModelNotFoundException` is thrown unless you set `$deleteWhenMissingModels = true`.

The **`#[WithoutRelations]` attribute** (available since Laravel 10.17) strips relationships before serialization — apply it per-property or at the class level in Laravel 12:

```php
use Illuminate\Queue\Attributes\WithoutRelations;

#[WithoutRelations]
class ProcessPodcast implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Podcast $podcast,
        public DistributionPlatform $platform,
    ) {}
}
```

### DTOs over Eloquent models

For jobs where you need a predictable data snapshot, **pass DTOs (Data Transfer Objects) instead of Eloquent models**. PHP 8.2+ readonly classes make this elegant:

```php
final readonly class PodcastData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $audioPath,
        public int $authorId,
    ) {}

    public static function fromModel(Podcast $podcast): self
    {
        return new self(
            id: $podcast->id,
            title: $podcast->title,
            audioPath: $podcast->audio_path,
            authorId: $podcast->author_id,
        );
    }
}
```

DTOs are fully serializable, immutable, carry no database re-fetching surprises, and produce smaller payloads. Spatie's `laravel-data` package provides advanced DTO support with validation and transformation.

---

## 2. Error handling, retries, and resilience

### The retry configuration hierarchy

Laravel provides **five complementary retry mechanisms**, and understanding how they interact prevents the most common production failures:

```php
class ProcessPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;            // Max total attempts
    public int $maxExceptions = 3;    // Max actual exceptions (not releases)
    public int $timeout = 120;        // Seconds before worker kills the job
    public bool $failOnTimeout = true; // Treat timeout as permanent failure

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300]; // Exponential backoff between retries
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(24);    // Time-based ceiling
    }
}
```

The distinction between `$tries` and `$maxExceptions` matters when using rate-limiting middleware. Rate-limited jobs are *released* back to the queue (counting as an "attempt") without throwing exceptions. Using `$maxExceptions` ensures jobs aren't prematurely marked as failed just because they were rate-limited several times. Combine `$maxExceptions` with `retryUntil()` for the most resilient pattern.

**The timeout alignment rule is critical**: `$job->timeout` < worker `--timeout` < queue connection `retry_after`. Violating this causes jobs to be processed twice or killed unexpectedly.

### try/catch and the failed() method

Inside `handle()`, differentiate between recoverable and permanent failures:

```php
public function handle(): void
{
    try {
        // Job logic
    } catch (RecoverableException $e) {
        $this->release(60); // Retry in 60 seconds
        return; // release() doesn't stop execution
    } catch (PermanentException $e) {
        $this->fail($e); // Mark as failed immediately
        return;
    }
}

public function failed(Throwable $exception): void
{
    Log::error("Payment job failed permanently", [
        'order_id' => $this->orderId,
        'exception' => $exception->getMessage(),
    ]);
    Notification::route('slack', config('services.slack.webhook'))
        ->notify(new JobFailedNotification($exception));
}
```

A subtle but important detail: both `$this->release()` and `$this->fail()` do **not** halt code execution. You must `return` after calling them.

### Job middleware — the production power tools

Laravel's built-in job middleware handles cross-cutting concerns declaratively. Define them via the `middleware()` method:

**WithoutOverlapping** prevents concurrent execution of jobs operating on the same resource. By default, the lock key includes the job class name — use `->shared()` to share locks across different job classes:

```php
public function middleware(): array
{
    return [
        (new WithoutOverlapping($this->user->id))
            ->expireAfter(300)
            ->shared(), // Lock shared across UpdateUser and DeleteUser jobs
    ];
}
```

**ThrottlesExceptions** implements a circuit-breaker pattern for unstable external services. In Laravel 11+, the second parameter changed from minutes to **seconds**:

```php
public function middleware(): array
{
    return [
        (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
            ->by('third-party-api')
            ->when(fn (Throwable $e) => $e instanceof HttpClientException),
    ];
}
```

**Skip** (Laravel 11+) conditionally deletes jobs that are no longer relevant:

```php
public function middleware(): array
{
    return [
        Skip::when($this->order->isCancelled()),
        Skip::unless(fn () => $this->isStillRelevant()),
    ];
}
```

**FailOnException** (Laravel 12.19+) immediately fails jobs for specific exception types without retrying — ideal for validation errors or irrecoverable states:

```php
public function middleware(): array
{
    return [new FailOnException([InvalidContentFormatException::class])];
}
```

**RateLimited** ties into Laravel's rate limiter definitions for fine-grained control, and **SkipIfBatchCancelled** auto-skips jobs in cancelled batches.

Custom middleware can be scaffolded with `php artisan make:job-middleware` and registered globally via `Bus::pipeThrough([])` in a service provider.

### Dispatch methods at a glance

| Method | Behavior |
|--------|----------|
| `::dispatch($data)` | Push to queue asynchronously |
| `::dispatchSync($data)` | Execute immediately, no queue |
| `::dispatchAfterResponse($data)` | Execute after HTTP response sent |
| `::dispatchIf($condition, $data)` | Conditional dispatch |
| `::dispatchUnless($condition, $data)` | Inverse conditional dispatch |
| `->afterCommit()` | Dispatch only after DB transaction commits |
| `->delay(now()->addMinutes(5))` | Delayed dispatch |
| `->onQueue('critical')` | Target specific queue |
| `->onConnection('sqs')` | Target specific connection |

**Always use `afterCommit()`** (or set `after_commit => true` globally in queue config) to prevent race conditions where workers pick up jobs before the transaction creating the relevant data has committed.

### Batching and chaining

**Job chains** execute sequentially — if one fails, the rest don't run:

```php
Bus::chain([
    new ProcessPodcast($podcast),
    new OptimizePodcast($podcast),
    new ReleasePodcast($podcast),
])->onQueue('podcasts')
  ->catch(fn (Throwable $e) => report($e))
  ->dispatch();
```

**Job batches** execute concurrently with lifecycle callbacks. Jobs must use the `Batchable` trait:

```php
Bus::batch([
    new ProcessPodcast(Podcast::find(1)),
    new ProcessPodcast(Podcast::find(2)),
])->then(fn (Batch $batch) => info('All completed!'))
  ->catch(fn (Batch $batch, Throwable $e) => report($e))
  ->finally(fn () => info('Batch finished'))
  ->allowFailures()
  ->name('podcast-processing')
  ->dispatch();
```

Chains can be nested within batches (pass arrays of jobs), and jobs can dynamically add more jobs to their batch via `$this->batch()->add()`. Note that **unique job constraints do not apply to jobs within batches**.

### Unique jobs

Implement `ShouldBeUnique` to prevent duplicate jobs. `ShouldBeUniqueUntilProcessing` releases the lock when the job *starts* (allowing re-queuing during processing):

```php
class UpdateSearchIndex implements ShouldQueue, ShouldBeUnique
{
    public $uniqueFor = 3600; // Lock duration

    public function uniqueId(): string
    {
        return $this->product->id;
    }
}
```

---

## 3. Queue connections and Redis configuration

### Why Redis is the production standard

Redis provides **sub-millisecond latency**, native support for Laravel Horizon, atomic operations via Lua scripts for reliable job reservation, and blocking pop (`BRPOP`) to eliminate wasteful polling. It is the only driver compatible with Horizon, which makes it the de facto production choice for any application that needs queue visibility.

The **database driver** ships as the default in new Laravel 11+ applications (alongside SQLite) and works for low-volume workloads. At scale, row lock contention between multiple workers competing for the `jobs` table degrades performance. SQS is fully managed and durable but introduces higher latency and lacks Horizon support.

### Separate Redis databases — a critical production requirement

A `cache:clear` command will **destroy queue jobs** if cache and queues share the same Redis database. Always isolate them:

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'), // phpredis, not predis
    'default' => ['database' => 0],
    'cache'   => ['database' => 1],
    'queue'   => ['database' => 2],
],
```

Use the native `phpredis` C extension for significantly better performance over the pure-PHP `predis` package. Set the Redis `maxmemory-policy` to **`noeviction`** for the queue database — unlike cache (which uses `allkeys-lru`), queue data must never be evicted.

### Blocking pop and retry_after

Set `block_for` to **3–5 seconds** for most workloads. This uses Redis `BRPOP`, where the worker blocks efficiently rather than polling in a tight loop. Never set `block_for => 0` (blocks indefinitely, preventing graceful shutdown).

The `retry_after` value determines when an "in-progress" job is re-released if the worker dies. **It must exceed your longest job timeout by several seconds**, or jobs will be processed twice:

```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'queue',
    'queue' => 'default',
    'retry_after' => 90,
    'block_for' => 5,
    'after_commit' => true,
],
```

### Laravel 12's new queue drivers

Laravel 12 introduced three notable additions:

- **Failover driver**: Automatically tries the next configured connection if the primary fails — `'connections' => ['redis', 'sqs', 'database']`.
- **Deferred driver**: Processes jobs synchronously after the HTTP response is sent, without a queue worker.
- **Background driver**: Serializes and runs jobs in a separate PHP process via `Concurrently::defer()`.
- **Pause/Resume**: Programmatically pause and resume specific queues via `Queue::pause('redis', 'default')` and `Queue::resume()`, also available as Artisan commands.

---

## 4. Horizon configuration and production tuning

### Supervisor architecture

Horizon's power lies in **environment-specific supervisor definitions** that map different queue segments to different resource profiles:

```php
'environments' => [
    'production' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue' => ['critical'],
            'balance' => 'simple',     // Fixed processes for predictability
            'processes' => 5,
            'tries' => 5,
            'timeout' => 60,
            'memory' => 512,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default', 'notifications', 'emails'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'tries' => 3,
            'timeout' => 180,
        ],
        'supervisor-bulk' => [
            'connection' => 'redis-long',
            'queue' => ['bulk', 'reports'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'timeout' => 600,
            'nice' => 10,          // Lower CPU priority than web processes
        ],
    ],
],
```

### Balancing strategies explained

**`auto`** dynamically adjusts worker count per queue based on workload. The `autoScalingStrategy` controls allocation: `'time'` allocates based on estimated time to clear the queue (better when job durations vary), while `'size'` allocates by raw job count (better for uniform jobs). **Important Laravel 12 clarification**: when using `auto`, queue order does *not* enforce priority. If strict priority matters, use `balance => false` or separate supervisors.

**`simple`** distributes a fixed process count evenly across queues — use for critical queues where you want guaranteed capacity regardless of load.

**`false`** processes queues strictly in listed order (like vanilla Laravel `--queue=high,default,low`) but still auto-scales total processes.

### Worker lifecycle management

Always use `--max-jobs` **and** `--max-time` together. `max-jobs` restarts workers after processing N jobs (preventing memory leak accumulation under high throughput), while `max-time` catches leaks even on low-traffic queues. Supervisor or Horizon automatically restarts workers after exit:

```bash
php artisan queue:work redis \
    --queue=critical,default \
    --max-jobs=500 \
    --max-time=3600 \
    --memory=256 \
    --sleep=3 \
    --tries=3 \
    --timeout=60
```

For process count, start with **one worker per CPU core** for CPU-bound jobs and **2–3× core count** for I/O-bound jobs (API calls, email sending).

### Deployment — the `horizon:terminate` imperative

Workers cache the application in memory. After every deployment, run `php artisan horizon:terminate` to gracefully stop Horizon (it completes current jobs before exiting). Supervisor auto-restarts it with the new code. The Supervisor config must include `stopwaitsecs` greater than your longest job timeout. The Horizon 5.43.0 release added `horizon:listen` for development, which watches for file changes and auto-restarts — never use this in production.

### Wait-time notifications and trim configuration

```php
'waits' => [
    'redis:critical' => 30,  // Alert if critical jobs wait >30 seconds
    'redis:default' => 60,
    'redis:bulk' => 120,
],
'trim' => [
    'recent' => 60,
    'completed' => 60,
    'recent_failed' => 10080, // Keep failed jobs 7 days
    'failed' => 10080,
],
```

Route notifications to Slack, email, or SMS via `HorizonServiceProvider::boot()`.

---

## 5. Monitoring, observability, and debugging

### The Context facade changes everything

Laravel 11's `Context` facade is the single most impactful improvement for queue observability. Context data set during an HTTP request **automatically propagates to dispatched jobs**, including through job chains:

```php
// In HTTP middleware
Context::add('trace_id', Str::uuid()->toString());
Context::add('user_id', auth()->id());

// In any queued job's handle() method — context is already available
public function handle(): void
{
    Log::info('Processing podcast', ['podcast_id' => $this->podcast->id]);
    // Output: Processing podcast {"podcast_id":95} {"trace_id":"e04e1a11...","user_id":42}
}
```

This eliminates the need for manual correlation ID passing through job constructors. Context data is "dehydrated" at dispatch time and "hydrated" at execution time. Use `Context::addHidden()` for sensitive data that shouldn't appear in logs.

### Job event hooks for centralized monitoring

Register listeners in `AppServiceProvider` to build a unified monitoring layer:

```php
Queue::before(function (JobProcessing $event) {
    Log::info('Job starting', [
        'job' => $event->job->resolveName(),
        'queue' => $event->job->getQueue(),
        'attempts' => $event->job->attempts(),
    ]);
});

Queue::failing(function (JobFailed $event) {
    Log::critical('Job permanently failed', [
        'job' => $event->job->resolveName(),
        'exception' => $event->exception->getMessage(),
    ]);
    // Trigger Sentry, Slack, PagerDuty
});
```

Laravel 11 added `JobQueueing` (fires *before* a job is pushed), enabling pre-dispatch inspection or prevention. The full event lifecycle: `JobQueueing` → `JobQueued` → `JobProcessing` → `JobProcessed`/`JobFailed`/`JobExceptionOccurred`.

### Tooling stack by environment

| Layer | Development | Production |
|-------|-------------|------------|
| Debugging | Telescope (full capture) | Telescope (exceptions only) or disabled |
| Dashboard | Horizon | Horizon + Pulse |
| Error tracking | Ignition/Flare | Sentry or Flare |
| Metrics | — | Prometheus/Grafana via `spatie/laravel-prometheus` |
| Logging | File + Context | Structured JSON + ELK/Datadog |
| Alerting | — | Horizon notifications + Slack + PagerDuty |

**Sentry integration** requires explicit wiring for queued job exceptions. Register a `Queue::failing` listener that calls `app('sentry')->captureException($event->exception)` to ensure all permanent failures reach Sentry.

**Prometheus metrics** via `spatie/laravel-prometheus` expose queue depth, throughput, failure rates, and supervisor status at a `/prometheus` endpoint. Freek Van der Herten reports using this on Flare, Mailcoach, and Oh Dear in production.

### Failed job management

Laravel's `failed_jobs` table serves as the dead letter queue. Schedule automated pruning:

```php
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
```

Retry specific jobs with `php artisan queue:retry <uuid>` or all with `queue:retry all`. For batch retries with filtering, `kirschbaum-development/laravel-queue-batch-retry` adds `--filter`, `--filter-by-exception`, and `--failed-after` flags.

### Health checks for Kubernetes

Expose a health endpoint that checks queue depth and worker status:

```php
Route::get('/health/queue', function () {
    $depth = Redis::llen('queues:default');
    return response()->json([
        'status' => $depth < 10000 ? 'healthy' : 'degraded',
        'queue_depth' => $depth,
    ], $depth < 10000 ? 200 : 503);
});
```

---

## 6. Clean architecture — where jobs belong

### Jobs live in the infrastructure layer

In Clean Architecture applied to Laravel, **jobs are infrastructure-layer concerns** because they are inherently coupled to Laravel's queue system. They *orchestrate* application-layer use cases but don't contain business logic themselves:

```
Domain/          → Entities, Value Objects, Repository interfaces (zero framework deps)
Application/     → Actions, DTOs, Command Handlers (business logic orchestration)
Infrastructure/  → Jobs, Eloquent Repositories, Listeners (framework-coupled)
Presentation/    → Controllers, Requests, Resources (HTTP-coupled)
```

The flow is: **Controller → validates → creates DTO → dispatches Job → Job calls Action → Action uses Domain interfaces → Infrastructure implements them**.

### The Action pattern — the community consensus

Spatie's Brent Roose and Freek Van der Herten established the Action pattern in "Laravel Beyond CRUD," and it has become the dominant approach. An Action is a single-purpose class encapsulating one business operation. As Freek notes: *"An action is just a command and its handler wrapped together."*

```php
// Application/Actions/ProcessOrderAction.php — framework-agnostic
class ProcessOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private NotificationServiceInterface $notifications,
    ) {}

    public function execute(int $orderId): void
    {
        $order = $this->orders->findOrFail($orderId);
        $order->process();
        $this->orders->save($order);
        $this->notifications->orderProcessed($order);
    }
}

// Infrastructure/Jobs/ProcessOrderJob.php — Laravel-coupled shell
class ProcessOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId) {}

    public function handle(ProcessOrderAction $action): void
    {
        $action->execute($this->orderId);
    }
}
```

Spatie's `spatie/laravel-queueable-action` eliminates the need for separate job classes entirely by making actions directly dispatchable: `$action->onQueue('pdf-generation')->execute($contract)`. Loris Leiva's `laravel-actions` takes this further — one class can serve as controller, job, listener, and console command.

### Event-driven dispatch patterns

Domain events decouple the "what happened" from the "what should happen next":

```php
// Domain event fired after order creation
event(new OrderPlaced($order));

// Queued listener handles the side effect
class SendOrderConfirmationEmail implements ShouldQueue
{
    public function handle(OrderPlaced $event): void
    {
        Mail::to($event->order->customer)->send(new OrderConfirmation($event->order));
    }
}
```

Use `ShouldQueueAfterCommit` to ensure listeners only fire after database transactions commit. Keep listeners focused on single side effects — heavy work should dispatch dedicated jobs.

### Minimizing framework coupling

The pragmatic approach (endorsed by Loris Leiva): accept that Laravel *is* the technical paradigm and contain coupling rather than eliminate it. Jobs use Laravel traits and interfaces — that's fine. The key is keeping *business logic* in framework-agnostic Action/Service classes where the only Laravel dependency is container injection via interfaces:

```php
// Domain interface — no Laravel imports
interface PaymentGatewayInterface
{
    public function charge(int $amountCents, string $idempotencyKey): PaymentResult;
}

// Infrastructure implementation — Laravel-coupled
class StripePaymentGateway implements PaymentGatewayInterface { /* ... */ }

// Bound in ServiceProvider
$this->app->bind(PaymentGatewayInterface::class, StripePaymentGateway::class);
```

### Testing strategy for CA-structured jobs

Test at three layers for complete coverage:

```php
// 1. Unit test the Action (fast, no framework needed)
public function test_process_order_applies_discount(): void
{
    $action = new ProcessOrderAction($mockRepo, $mockNotifier);
    $action->execute(42);
    // Assert business logic outcomes
}

// 2. Unit test the Job (verify it calls the right action)
public function test_job_delegates_to_action(): void
{
    $mockAction = Mockery::mock(ProcessOrderAction::class);
    $mockAction->shouldReceive('execute')->once()->with(42);
    $this->app->instance(ProcessOrderAction::class, $mockAction);
    app()->call([new ProcessOrderJob(42), 'handle']);
}

// 3. Integration test (verify dispatch from controller)
public function test_order_endpoint_dispatches_job(): void
{
    Bus::fake();
    $this->post('/api/orders', $data);
    Bus::assertDispatched(ProcessOrderJob::class, fn ($job) => $job->orderId === 1);
}
```

Test chains with `Bus::assertChained()`, batches with `Bus::assertBatched()`, and use Laravel 11's `withFakeQueueInteractions()` to test that jobs correctly call `release()`, `fail()`, or `delete()` without a real queue.

---

## 7. Common pitfalls and production war stories

The most dangerous pitfalls are subtle. **Dispatching before transaction commit** causes workers to process jobs against data that doesn't exist yet — always use `afterCommit()`. **Not restarting workers after deployment** means workers run stale code from memory — `horizon:terminate` must be in every deploy script. **Ignoring the timeout chain** (`job timeout` < `worker timeout` < `retry_after`) causes duplicate processing.

**N+1 queries inside jobs** are invisible without monitoring. Always re-query with eager loading inside `handle()` and use `chunkById()` for large datasets. **Large serialized payloads** choke Redis — strip relationships with `#[WithoutRelations]` and pass only IDs or DTOs. **Closure-based jobs** carry serialized code that can break across deployments when code changes; prefer concrete job classes for anything beyond trivial tasks.

The **soft-delete gotcha** with `$deleteWhenMissingModels`: soft-deleted models are still found by the query builder, so this property doesn't silently discard jobs for soft-deleted records as you might expect.

---

## Conclusion

The Laravel 11/12 queue ecosystem has matured into a genuinely production-grade system. The unified `Queueable` trait reduces boilerplate, the `Context` facade solves the correlation ID problem elegantly, and middleware like `Skip`, `FailOnException`, and `ThrottlesExceptions` provide declarative resilience patterns that previously required custom code. The new pause/resume and failover capabilities in Laravel 12 address real operational needs.

The architectural principles remain constant: **jobs are thin, idempotent orchestrators** in the infrastructure layer. Business logic lives in Actions or Services. DTOs beat Eloquent models as job arguments for predictability. Redis plus Horizon is the production standard, with separate databases, `noeviction` memory policy, and `after_commit` enabled globally.

The most impactful investments for any team are: enforcing idempotency from day one, adopting the Action pattern to keep jobs testable and focused, configuring Horizon with tiered supervisors matching your workload profile, wiring queue event hooks into your monitoring stack early, and using the `Context` facade to make every log line traceable across the entire request-to-job pipeline.
