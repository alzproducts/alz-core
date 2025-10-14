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
# Infection (comprehensive)
./vendor/bin/sail composer test:ai        # Tests + Infection validation (recommended)
./vendor/bin/sail composer infection      # Infection only
./vendor/bin/sail composer infection:ci   # Infection CI mode

# Pest Mutate (fast, alternative engine)
./vendor/bin/sail pest --mutate --everything --covered-only --min=90

# Defense in depth (run both)
./vendor/bin/sail composer test:ai && ./vendor/bin/sail pest --mutate --everything --covered-only --min=90
```

**Configuration**:
- Infection: See `infection.json5` for 80+ mutator settings
- Pest Mutate: Zero config required, uses `--everything --covered-only` flags

**Git Hooks**:
- Both mutation engines available as pre-push hooks (commented out by default)
- Enable in `config/git-hooks.php` for automatic validation
- See file comments for dual-engine strategy explanation