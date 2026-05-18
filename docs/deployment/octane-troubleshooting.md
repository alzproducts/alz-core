# Laravel Octane Troubleshooting Guide

Common issues and solutions when working with Laravel Octane and Swoole.

---

## Installation Issues

### Issue: "Extension swoole is not installed"

**Symptoms**:
```
Laravel\Octane\Exceptions\DependencyMissingException
Extension swoole is not installed
```

**Solutions**:

**Local Development (Sail)**:
1. Verify `ext-swoole` is in `composer.json`:
   ```json
   "require": {
       "ext-swoole": "*"
   }
   ```
2. Restart Sail containers:
   ```bash
   ./vendor/bin/sail down
   ./vendor/bin/sail up -d
   ```
3. Verify Swoole is loaded:
   ```bash
   ./vendor/bin/sail exec laravel.test php -m | grep swoole
   ```

**Production (Railway)**:
- Railway Nixpacks auto-detects `ext-swoole` in `composer.json` and installs it during build
- Check build logs for: `Installing PHP extensions: swoole`
- If missing, ensure `ext-swoole` is in the `require` section (not `require-dev`)

---

## Development Workflow Issues

### Issue: Code changes not reflected

**Symptoms**:
- Updated code but seeing old behavior
- New routes not found
- Configuration changes ignored

**Solutions**:

**Option 1: Use watch mode** (recommended for development)
```bash
./vendor/bin/sail artisan octane:start --watch
```
Automatically reloads workers when files change.

**Option 2: Manual reload**
```bash
./vendor/bin/sail artisan octane:reload
```

**Option 3: Restart server**
```bash
# Stop: Ctrl+C in terminal where Octane is running
# Start:
./vendor/bin/sail artisan octane:start
```

**What NOT to do**:
- ❌ Don't restart Sail containers for code changes
- ❌ Don't clear cache (Octane already handles this)

---

## State Management Issues

### Issue: Stale user data across requests

**Symptoms**:
- User A sees User B's data
- Authentication state persists incorrectly
- Session data bleeds between requests

**Root Cause**: Constructor injection of `Request` or `Application` container.

**Bad Pattern**:
```php
class OrderService {
    public function __construct(
        private Request $request,  // ❌ Stale across requests
        private Application $app   // ❌ Stale across requests
    ) {}
}
```

**Good Pattern**:
```php
class OrderService {
    public function process() {
        $request = request();  // ✅ Fresh per request
        $app = app();          // ✅ Fresh per request
    }
}
```

**Fix**:
1. Search for problematic patterns:
   ```bash
   grep -r "public function __construct.*Request" app/
   grep -r "public function __construct.*Application" app/
   ```
2. Replace constructor injection with runtime resolution
3. Test with multiple concurrent requests

---

### Issue: Database transaction leaks

**Symptoms**:
- Transactions from previous requests still open
- "Already in transaction" errors
- Data corruption across requests

**Root Cause**: Uncommitted transactions persist across Octane worker reuse.

**Bad Pattern**:
```php
DB::beginTransaction();
// ... queries
DB::commit();  // If exception occurs, transaction never committed
```

**Good Pattern**:
```php
// Preferred: Automatic rollback on exception
DB::transaction(function () {
    // ... queries
});

// If manual control needed:
try {
    DB::beginTransaction();
    // ... queries
    DB::commit();
} catch (\Exception $e) {
    DB::rollback();
    throw $e;
}
```

**Fix**:
1. Search for manual transactions:
   ```bash
   grep -r "DB::beginTransaction()" app/
   ```
2. Refactor to use closure pattern or proper try/catch/finally
3. Add tests for transaction rollback on exceptions

---

## Memory Issues

### Issue: Memory usage grows over time

**Symptoms**:
- Workers consuming increasing memory
- OOM (Out of Memory) errors
- Slow responses over time

**Solutions**:

**1. Lower max_request** (force worker restart):
```env
# .env
OCTANE_MAX_REQUESTS=250  # Down from 500
```

**2. Reduce worker count** (if on limited resources):
```env
OCTANE_WORKERS=2          # Down from 4
OCTANE_TASK_WORKERS=2     # Down from 6
```

**3. Monitor worker memory**:
```bash
./vendor/bin/sail artisan octane:status
```

**4. Check for memory leaks**:
- Large objects stored in static properties
- Uncleared event listeners
- Circular references preventing garbage collection

**5. Enable garbage collection** (config/octane.php):
```php
'listeners' => [
    OperationTerminated::class => [
        FlushOnce::class,
        FlushTemporaryContainerInstances::class,
        DisconnectFromDatabases::class,  // Uncomment this
        CollectGarbage::class,            // Uncomment this
    ],
],
```

---

## Performance Issues

### Issue: Slower than expected

**Symptoms**:
- Octane not faster than php-fpm
- Response times similar to traditional deployment

**Diagnosis**:

**1. Check worker count**:
```bash
./vendor/bin/sail artisan octane:status
```
Should show configured number of workers running.

**2. Verify worker reuse**:
Check logs for frequent worker restarts (indicates max_request too low).

**3. Profile with Telescope**:
```bash
./vendor/bin/sail artisan octane:start
# Visit: http://localhost:8000/telescope
```
Look for:
- Database query counts (N+1 queries)
- Slow queries
- External API calls blocking requests

**4. Check for blocking operations**:
```php
// ❌ Bad: Blocks worker
$response = Http::get('https://slow-api.com/data');

// ✅ Good: Use queued jobs for slow operations
dispatch(new ProcessSlowApi());
```

---

## Testing Issues

### Issue: Tests fail with Octane enabled

**Symptoms**:
- Tests pass without Octane
- Tests fail when Octane server is running

**Solutions**:

**1. Run tests without Octane server**:
```bash
# Stop Octane server (Ctrl+C)
# Run tests
make test
```

**2. Use RefreshDatabase trait**:
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderTest extends TestCase {
    use RefreshDatabase;  // Ensures clean database per test
}
```

**3. Clear state between tests**:
```php
protected function tearDown(): void {
    // Clear static caches, singletons, etc.
    parent::tearDown();
}
```

---

## Production Issues

### Issue: Workers crashing on Railway

**Symptoms**:
- Frequent worker restarts in logs
- Health check failures
- 502 Bad Gateway errors

**Diagnosis**:

**1. Check Railway logs**:
```
# Look for:
- "Worker process has been stopped"
- "Segmentation fault"
- Memory errors
```

**2. Reduce resource usage**:
```env
# Railway environment variables
OCTANE_WORKERS=2          # Reduce from 4
OCTANE_TASK_WORKERS=2     # Reduce from 6
```

**3. Increase max_request**:
```env
OCTANE_MAX_REQUESTS=1000  # Up from 500
```
Prevents excessive worker restarts.

**4. Check for fatal errors**:
- Review application error logs
- Look for uncaught exceptions
- Check for infinite loops

---

### Issue: Health check failing

**Symptoms**:
- Railway deployment fails with "Health check timeout"
- `/up` endpoint returns 404 or times out

**Solutions**:

**1. Verify health check route exists** (routes/web.php):
```php
Route::get('/up', function () {
    return response()->json(['status' => 'ok'], 200);
});
```

**2. Test locally**:
```bash
./vendor/bin/sail artisan octane:start
curl http://localhost:8000/up
```

**3. Check Octane binding**:
Railway start command must use:
```bash
php artisan octane:start --server=swoole --host=0.0.0.0 --port=${PORT:-8000}
```

**4. Verify Railway health check path**:
- Railway Dashboard → Web Service → Settings → Deploy
- **Health Check Path**: `/up` (not `/health`)

---

## Common Questions

### Q: Should I use Octane for development?

**A**: Use `--watch` mode for best experience:
```bash
./vendor/bin/sail artisan octane:start --watch
```

Alternatively, use `php artisan serve` for development and Octane only for production.

---

### Q: How do I debug issues in Octane?

**A**:
1. **Use Telescope**: http://localhost:8000/telescope
2. **Check worker status**: `octane:status`
3. **Review logs**: `./vendor/bin/sail logs -f`
4. **Add debug output**: Use `logger()->info()` (appears in logs)

---

### Q: Can I use XDebug with Octane?

**A**: Yes, but with caveats:
```bash
# Disable Swoole coroutines for debugging
SWOOLE_USE_SHORTNAME=Off ./vendor/bin/sail artisan octane:start
```

For better debugging experience, use `php artisan serve` during debugging sessions.

---

### Q: How do I monitor worker health in production?

**A**:
```bash
# SSH into Railway container
railway run bash

# Check worker status
php artisan octane:status

# View worker metrics
ps aux | grep swoole
```

---

## Quick Reference

### Useful Commands

```bash
# Start Octane (development with auto-reload)
./vendor/bin/sail artisan octane:start --watch

# Start Octane (production)
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000

# Reload workers (after code changes)
./vendor/bin/sail artisan octane:reload

# Check worker status
./vendor/bin/sail artisan octane:status

# Stop Octane
./vendor/bin/sail artisan octane:stop
```

### Configuration Files

- **Octane config**: `config/octane.php`
- **Environment variables**: `.env` (OCTANE_*)
- **Railway deployment**: `docs/deployment/railway-octane-setup.md`

### Getting Help

1. Check Laravel Octane docs: https://laravel.com/docs/12.x/octane
2. Review this troubleshooting guide
3. Search issue tracker: https://github.com/laravel/octane/issues
4. Check Swoole docs: https://www.swoole.co.uk/
