# Testing Guide

This file provides testing guidance for this Laravel project.

**⚠️ Read `tests/TestingStrategy.md` first** — required before writing any tests. Defines layer policies and the "Should I Write a Test?" framework.

## MUTATION TESTING: AI Test Quality Validation

**Primary Purpose**: Catch weak assertions in AI-generated tests.

**Engine**: Pest Mutate (190+ mutators including Laravel-specific ones).

### The Problem

LLMs generate tests that look good but often use weak assertions:
```php
// AI generates (passes, 100% coverage):
$this->assertNotNull($result);  // ❌ Doesn't validate actual behavior

// Should be:
$this->assertEquals('expected-value', $result);  // ✅ Validates correctness
```

### AI Test Generation Workflow

**Step 1**: Use `zen:testgen` MCP to analyze the class and generate test outline
```
Use zen:testgen to create tests for App\Infrastructure\Support\RetryAfterParser
```

**Step 2**: Write tests and iterate until they pass
```bash
php artisan test --filter=RetryAfterParser
```

**Step 3**: Validate test quality with mutation testing
```bash
make mutate-domain    # Domain layer (90%+ threshold)
make mutate-app       # Application layer (70%+ threshold)
make pest-mutate      # All code (85% threshold)
```

**Step 4**: Fix escaped mutants until MSI meets layer threshold

### Expected Results

- **Your manual tests**: 100% MSI (baseline established)
- **AI first run**: 65-75% MSI (normal)
- **After fixes**: 85%+ MSI target

### Common AI Weaknesses

| AI generates | Should be |
|--------------|-----------|
| `assertNotNull($x)` | `assertEquals('value', $x)` |
| `assertTrue($x > 0)` | `assertSame(99.99, $x)` |
| `assertTrue($status)` | `assertSame(200, $statusCode)` |

### Interpreting Results

When Pest Mutate reports escaped mutants:
- **EqualIdentical**: Use `assertSame()` not `assertEquals()`
- **TrueValue**: Replace generic `assertTrue()` with specific assertions
- **NotIdentical**: Replace `assertNotNull()` with actual value checks

### Commands

```bash
# Run tests
make test-quick        # Domain tests only (~5s, no external deps)
make test              # All tests (unit + integration)

# Quick validation
make test-ai           # Tests + Pest Mutate (85% threshold)

# Comprehensive validation (per-layer thresholds)
make test-mutate       # Tests + Domain (90%) + Application (70%)

# Individual mutation targets
make pest-mutate       # All code (85% threshold)
make mutate-domain     # Domain layer only (90%+ threshold)
make mutate-app        # Application layer only (70%+ threshold)
```

**Script Breakdown**:
- `test:ai`: Tests + Pest Mutate with 85% threshold
- `test:mutate`: **Recommended** - Tests + per-layer mutation with strict thresholds
- `mutate:domain`: Domain layer, 90%+ minimum (excludes exceptions)
- `mutate:app`: Application layer, 70%+ minimum (covered only)
- `pest:mutate`: All code, 85% minimum

**Configuration**:
- Pest Mutate: No config file needed, flags in Makefile targets
- Thresholds: `--min=90` (Domain), `--min=70` (Application), `--min=85` (global)

---

## ⚠️ IMPORTANT: Pre-PR Coverage Check

**After creating tests for a feature, ALWAYS run coverage checks before creating a PR:**

```bash
make test-coverage   # Runs Domain (90%) + Application (70%) checks in parallel
```

This catches coverage regressions early. The PR gate will fail if coverage drops below thresholds.

---

## Code Coverage Strategy

**Layer targets defined in `tests/TestingStrategy.md`.**

**Test**: Runtime business logic, error paths, transformations, API interactions
**Exclude**: Boot-time validation, framework boilerplate, deployment config

**Excluded in `phpunit.xml`**:
- `*Factory.php` - Boot-time config validation (fail-fast at startup)
- `*ServiceProvider.php`, `*Exception.php` - Framework boilerplate

**Layer-Specific Targets**:
- Domain: 90%+ coverage, 85%+ MSI
- Application Services/Transformers: 70%+ coverage, 70%+ MSI
- Infrastructure/Presentation: No mutation testing (low ROI)

**Commands**:
```bash
make test-domain-coverage   # Domain with 90% threshold
make test-app-coverage      # Application with 70% threshold
make mutate-domain          # Domain mutation testing (90%+) - uses Pest mutate
make mutate-app             # Application mutation testing (70%+) - uses Pest mutate
```

**Note**: Both layers use Pest Mutate (190+ mutators including Laravel-specific ones).

---

## Fixing Code Coverage Failures in PRs

### Configuration Files
| File | Purpose |
|------|---------|
| `phpunit.xml` | CI → Codecov (all layers) |
| `phpunit-domain.xml` | Local 90% Domain check |
| `phpunit-app.xml` | Local 70% Application check |
| `codecov.yml` | Mirror exclusions (belt-and-suspenders) |

### Diagnose
```bash
make test-domain-coverage  # 90% target
make test-app-coverage     # 70% target
make coverage-html && open coverage-report/index.html
```

### Test vs Exclude Decision
```
Data container with no logic? → EXCLUDE
├─ Events, simple Results, pure delegation UseCases
Has validation/branching/transformation? → TEST
├─ Value objects with Assert::*, DTOs with normalization
├─ UseCases with conditionals, Transformers
```

### Adding Exclusions

**1. Layer config** (`phpunit-domain.xml` or `phpunit-app.xml`):
```xml
<exclude>
    <file>app/Domain/Path/To/File.php</file>
    <directory suffix="Event.php">./app/Domain</directory>
</exclude>
```

**2. Main config** (`phpunit.xml`) — same exclusions

**3. Codecov** (`codecov.yml`):
```yaml
ignore:
  - "app/Path/To/File.php"
  - "**/*Event.php"
```

**In-source alternative** (one-offs only): `@codeCoverageIgnore` annotation

---

## Testing ShouldBeUnique jobs: avoid Queue/Bus assertPushed/assertDispatched

`ShouldBeUnique` jobs + `Queue::fake()`/`Bus::fake()` cause intermittent parallel failures. The dispatch assertion (`assertPushed`/`assertDispatched`) fails because of cache lock contention and `PendingDispatch::__destruct()` timing issues in parallel workers.

Fix: use `Queue::fake()` to prevent real dispatch, but verify the happy path via Mockery expectations on the line **after** the dispatch call (e.g., a logger mock). This proves the code path completed without relying on flaky facade assertions.

---

## Debugging test failures

### Pre-push hook says "Tests failed!" but all tests look like they pass

**Check Redis first**: `make redis` — if Redis is down, cache-dependent tests fail silently in `--parallel` mode.

**Root cause**: `--parallel` (ParaTest) hides warnings and error details. PHPUnit warnings (not just failures) trigger exit code 1 when `failOnWarning="true"` in `phpunit.xml`. The parallel runner shows all dots as passes but returns non-zero.

**Step 1 — Get the real error** by running sequentially and capturing output:
```bash
php vendor/bin/pest --no-progress > tmp/test-output.txt 2>&1; echo "EXIT: $?"
cat tmp/test-output.txt | sed 's/\x1b\[[0-9;]*m//g' | tail -30
```

**Step 2 — Look for warnings (`!`)** in sequential output. Common causes:

| Warning | Cause | Fix |
|---------|-------|-----|
| `not a valid target for code coverage` | `#[CoversClass]` on a class excluded in `phpunit.xml` (e.g. `*Factory.php`) | Use `#[CoversNothing]` instead |
| Deprecation notices (`!` markers) | PHP 8.4 deprecations in app code | Fix the deprecated usage, or set `failOnDeprecation="false"` in `phpunit.xml` |
| Skipped tests (`s` markers) | `markTestSkipped()` in `--parallel` mode | ParaTest may return exit 1 for skips — investigate the skip or exclude the test from parallel runs |

**Step 3 — If output is truncated** (agent CLI / CI), redirect to a file and read the tail:
```bash
make test > tmp/test-output.txt 2>&1
cat tmp/test-output.txt | sed 's/\x1b\[[0-9;]*m//g' | tail -30
```

### phpunit.xml exit code flags

These flags in `phpunit.xml` control whether issues cause exit code 1:
```xml
failOnWarning="true"        <!-- PHPUnit warnings → exit 1 (our default) -->
failOnDeprecation="false"   <!-- PHP deprecation notices → exit 0 -->
failOnNotice="true"         <!-- PHP notices → exit 1 -->
failOnPhpunitDeprecation="false"  <!-- PHPUnit internal deprecations → exit 0 -->
```

### GITHOOKS_DEBUG env vars

Add to `.env` to debug the `igorsgm/laravel-git-hooks` package:
```bash
GITHOOKS_DEBUG_OUTPUT=true    # Show full output of each hook command
GITHOOKS_DEBUG_COMMANDS=true  # Show the commands being executed
GITHOOKS_OUTPUT_ERRORS=true   # Show error output from hooks
```
**Note**: These only affect the package's built-in analyzers (Pint, Larastan pre-commit hooks). Our custom `AbstractProcessHook` (used for Pest, Deptrac, TLint pre-push) uses Symfony Process directly and outputs results via `$process->getOutput()` — the debug flags above have no effect on it. For pre-push debugging, use Step 1 above.

---

## Mocking External SDKs with Strict Return Types

**Key lesson**: Third-party SDKs (Google Ads, Firebase, etc.) enforce strict return type checking on mocks. This isn't a limitation—it's a feature preventing production bugs.

**The problem**:
```php
// ❌ FAILS - Wrong return type
$response = $this->getMockBuilder(SearchGoogleAdsResponse::class)->getMock();
// Error: Method search() declares return type PagedListResponse, not SearchGoogleAdsResponse
```

**The solution**:
```php
// ✅ CORRECT - Mock the actual declared return type
$response = $this->getMockBuilder(PagedListResponse::class)
    ->disableOriginalConstructor()
    ->onlyMethods(['iterateAllElements'])  // ← Real methods use onlyMethods()
    ->getMock();
```

**Rules**:
- **Match declared return types exactly** from the SDK's method signatures
- **Use `onlyMethods()`** for methods that exist on the real class (not `addMethods()`)
- **Use Reflection** for accessing protected properties on SDK exceptions:
  ```php
  $prop = new ReflectionProperty($exception, 'metadata');
  $prop->setAccessible(true);
  $prop->setValue($exception, ['retry-after' => '180']);
  ```