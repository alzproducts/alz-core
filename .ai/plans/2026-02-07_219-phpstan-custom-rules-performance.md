# PHPStan Custom Rules: Performance Audit & Optimizations

## Context

20 custom PHPStan rules were added in #217. PHPStan Community Edition (2.1.38) lacks per-rule profiling, so we need manual benchmarking + code-level optimizations.

## Benchmarking Strategy

PHPStan CE doesn't have `--profile-rules`. Practical approach:

1. **Baseline**: `time make analyse` (clear cache first: `php vendor/bin/phpstan clear-result-cache`)
2. **Without custom rules**: Comment out `phpstan-custom-rules.neon` include, re-run — diff = custom rule overhead
3. **Per-batch**: Re-enable batches one at a time to identify worst offenders
4. **Debug mode**: `php vendor/bin/phpstan analyse --debug` shows file-by-file progress

## Findings: Rule Cost Analysis

### Ranked by Estimated Cost (worst → best)

| # | Rule | Node Type | Cost Driver | Est. Impact |
|---|------|-----------|-------------|-------------|
| 🔴1 | `RowClassNotImportedOutsideQueriesRule` | `InClassNode` | `file_get_contents` for **every class** outside Queries | ~150 file reads |
| 🟠2 | `NoSdkExceptionsInThrowsRule` | `ClassMethod` | `file_get_contents` + regex for every Infra public method with `@throws` | ~25-70 file reads |
| 🟡3 | `NoEventDispatchOutsideApplicationRule` *(disabled)* | `Expr` | Fires on **every expression** in codebase | Thousands of invocations |
| 🟢4 | `NoDbFacadeRule` | `StaticCall` | `file_get_contents` but well-guarded (only when name='DB') | ~5-10 file reads |
| 🟢5 | `NoArtisanCallRule` | `StaticCall` | Same pattern as NoDbFacade | ~0-2 file reads |
| ✅ | All other 14 rules | Various | Namespace checks, string comparisons, reflection | Negligible |

## Optimization Plan

### P0: RowClassNotImportedOutsideQueriesRule — Eliminate all file I/O

**File**: `app/DevTools/PHPStan/Rules/Infrastructure/RowClassNotImportedOutsideQueriesRule.php`

**Problem**: Registers for `InClassNode`, reads the entire file for every class in the codebase to regex-match use statements. ~150 file reads per analysis run.

**Fix**: Change node type from `InClassNode` to `Node\Stmt\Use_`. Check each use statement's imported name against the `App\Infrastructure\*\Queries\*Row` pattern directly from the AST — no file I/O needed.

```php
// Before: InClassNode → file_get_contents → regex
// After:  Use_ → check $use->name->toString() against pattern
```

**Impact**: ~150 file reads → 0 file reads. Largest single optimization.

### P1: NoSdkExceptionsInThrowsRule — Cache use-map per file

**File**: `app/DevTools/PHPStan/Rules/Infrastructure/NoSdkExceptionsInThrowsRule.php`

**Problem**: `buildUseMap()` calls `file_get_contents` for every Infrastructure method with `@throws`. When a file has 5 public methods, the same file is read 5 times.

**Fix**: Add a static cache (`private static array $useMapCache = []`) keyed by file path. PHPStan rules run in CLI, not Octane — static properties are safe here.

**Suppression needed**: `NoStaticPropertiesRule` flags `App\DevTools\*` since it checks `App\*`. Add an `ignoreErrors` entry in `phpstan.neon` for `alz.noStaticProperties` on this file path.

**Impact**: ~25-70 file reads → ~15-30 file reads (one per file instead of one per method).

### P2: NoEventDispatchOutsideApplicationRule — Narrow node type (when re-enabled)

**File**: `app/DevTools/PHPStan/Rules/Architecture/NoEventDispatchOutsideApplicationRule.php`

**Problem**: Registers for `Expr::class` — fires on every expression (variables, literals, assignments, etc.). Only 3 patterns actually matter: `event()`, `::dispatch()`, `->dispatch()`.

**Fix**: Change `Expr::class` to `PhpParser\Node\Expr\CallLike::class`. This matches `FuncCall`, `StaticCall`, `MethodCall` (and `New_`, `NullsafeMethodCall`) but excludes all non-call expressions.

**Alternative** (more surgical): Split into 2-3 rules registered for `FuncCall`, `StaticCall`, `MethodCall` specifically.

**Impact**: ~80% fewer rule invocations when re-enabled.

### Skipped: NoDbFacadeRule / NoArtisanCallRule

Low payoff (~5-10 file reads), medium risk (Larastan facade resolution uncertainty). Not worth changing.

## Verification

1. Clear PHPStan cache: `php vendor/bin/phpstan clear-result-cache`
2. Baseline timing: `time make analyse`
3. Apply optimizations
4. Clear cache again, re-time: `time make analyse`
5. Run `make lint` — zero new errors
6. Run `make test` — all tests pass (rule unit tests)

## Non-Optimizations (already efficient)

The remaining 14 rules are already well-optimized:
- **Job rules (6)**: All guard with `App\Application\Jobs` namespace check — only ~10-20 classes match
- **TryCatch rules (3)**: Rare node type (~30-80 occurrences), instant checks
- **Domain/Exception rules (2)**: Narrow namespace guard
- **NoStaticPropertiesRule**: Property node + `isStatic()` — instant
- **NoAssertEqualsRule**: Method name check — instant
- **SchemaQualifiedTableNameRule**: Well-layered guards before reflection
