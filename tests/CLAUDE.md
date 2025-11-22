# Testing Guide

This file provides testing guidance for this Laravel project.

## MUTATION TESTING: AI Test Quality Validation

**Primary Purpose**: Catch weak assertions in AI-generated tests.

**Strategy**: Dual mutation testing engines for defense in depth:
- **Infection**: Mature, comprehensive mutation engine (80+ mutators)
- **Pest Mutate**: Fast, Pest-native mutations with different strategies

Using both engines catches more weak tests than either alone.

### The Problem

LLMs generate tests that look good but often use weak assertions:
```php
// AI generates (passes, 100% coverage):
$this->assertNotNull($result);  // ❌ Doesn't validate actual behavior

// Should be:
$this->assertEquals('expected-value', $result);  // ✅ Validates correctness
```

### Workflow

```bash
# 1. Ask AI to generate tests
# 2. Run mutation validation
./vendor/bin/sail composer test:ai

# 3. Fix escaped mutants until MSI ≥ 85%
```

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

When Infection reports "escaped mutants":
- **EqualIdentical**: Use `assertSame()` not `assertEquals()`
- **TrueValue**: Replace generic `assertTrue()` with specific assertions
- **NotIdentical**: Replace `assertNotNull()` with actual value checks

### Prompting AI Better

**Good prompt**:
> "Write PHPUnit tests for OrderService. Use assertEquals() with exact expected values, not assertNotNull(). Include edge cases and data providers."

### Commands

```bash
# Quick validation (single engine)
./vendor/bin/sail composer test:ai           # Tests + Infection (exploratory, no thresholds)

# Comprehensive validation (both engines with thresholds)
./vendor/bin/sail composer test:mutate       # Tests + Pest Mutate + Infection Strict

# Individual mutation engines
./vendor/bin/sail composer infection         # Infection only (exploratory)
./vendor/bin/sail composer infection:strict  # Infection with thresholds (70%/80%)
./vendor/bin/sail composer pest:mutate       # Pest Mutate with 90% threshold
./vendor/bin/sail composer infection:ci      # CI mode with GitHub logger
```

**Script Breakdown**:
- `test:ai`: Original workflow (tests + exploratory Infection)
- `test:mutate`: **Recommended** - Runs both mutation engines with strict thresholds
- `infection`: Interactive exploration, no minimum thresholds
- `infection:strict`: Enforces 70% MSI / 80% Covered MSI (same as git hook)
- `pest:mutate`: Enforces 90% minimum score (different mutation strategy)

**Configuration**:
- All mutation testing config centralized in `composer.json` scripts
- Infection mutators: See `infection.json5` for 80+ mutator settings
- Pest Mutate: No config file needed, flags in composer script
- Thresholds: `--min=90` (Pest) and `--min-msi=70 --min-covered-msi=80` (Infection)

**Git Hooks**:
- Both mutation engines **enabled** as pre-push hooks by default
- Hooks call composer scripts (centralized config)
- Disable in `config/git-hooks.php` if too slow
- See file comments for dual-engine strategy explanation

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