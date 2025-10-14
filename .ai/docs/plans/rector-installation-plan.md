# Rector Installation & Configuration Plan

**Created**: 2025-10-14
**Completed**: 2025-10-14
**Status**: ✅ Completed
**Objective**: Install Rector to auto-convert PHPUnit annotations to attributes, solving deprecation warnings and preparing for future PHP/Laravel upgrades.

**Final Outcome**: Successfully installed and configured Rector with Laravel 120 support. Converted PHPUnit annotations to PHP 8+ attributes, applied PHP 8.4 modernizations (typed constants, #[Override] attributes, arrow functions), and validated end-to-end workflow. All tests and linters passing.

---

## 📦 What We'll Install

1. **`rector/rector`** - Core refactoring tool (free, open-source)
2. **`rector/rector-laravel`** - Laravel-specific rules (optional but recommended)

## 🗂️ Files We'll Create/Modify

### 1. **composer.json** (Modify)
- Add Rector packages to `require-dev`
- Add 3 new composer scripts:
  - `rector` - Run Rector refactoring
  - `rector:dry-run` - Preview changes without applying
  - `refactor` - Rector + Pint combo (refactor then format)

### 2. **rector.php** (Create New)
- Minimal configuration targeting:
  - PHPUnit 10 annotation→attribute conversion
  - Laravel best practices (via Laravel provider)
  - Scan paths: `app/`, `tests/`
  - Skip paths: `bootstrap/`, `vendor/`

### 3. **.gitignore** (Modify)
- Add Rector cache directory to gitignore

### 4. **CLAUDE.md** (Update Documentation)
- Add Rector to "Code Quality & Linting" section
- Document when to use Rector vs Pint
- Add workflow order: Rector → Pint → PHPStan

### 5. **config/git-hooks.php** (Optional - Your Choice)
- If you want: Add `RectorPreCommitHook` before Pint
- If not: Keep Rector manual-only for intentional refactoring

## 🔄 Immediate Action Plan

### Phase 1: Installation (2 minutes)
```bash
./vendor/bin/sail composer require rector/rector rector/rector-laravel --dev
```

### Phase 2: One-Time Conversion (1 minute)
```bash
./vendor/bin/sail composer rector:dry-run tests/  # Preview
./vendor/bin/sail composer rector tests/          # Apply
./vendor/bin/sail composer fix                     # Clean up formatting
```

**Result**: All annotations in `HorizonBasicAuthTest.php` converted:
- `@test` → `#[Test]`
- `@dataProvider` → `#[DataProvider('methodName')]`
- `@covers` → `#[CoversClass(HorizonBasicAuth::class)]`

### Phase 3: Verification (1 minute)
```bash
./vendor/bin/sail composer test    # Tests still pass
./vendor/bin/sail composer lint    # All linters happy
```

## ⚙️ Configuration Details

### Rector Config Strategy
**Conservative Approach** (Recommended):
- Only PHPUnit attribute conversion rules
- Laravel code quality rules
- No aggressive refactoring (safe for production)

### Tool Execution Order
```
1. Rector  - Refactor code structure
2. Pint    - Format output
3. PHPStan - Verify types still valid
4. Tests   - Ensure functionality preserved
```

## 🎛️ Git Hook Integration (Your Decision)

### Option A: Manual Only (Recommended for Now)
**Pros**:
- Explicit control over when refactoring happens
- No surprise changes during commits
- Perfect for learning the tool

**Use When**:
- Upgrading PHP/Laravel versions
- Applying new coding standards
- Fixing deprecations

### Option B: Automated Pre-Commit Hook
**Pros**:
- Enforces standards automatically
- Team consistency
- Catches issues early

**Cons**:
- Commits become slower (~2-3 seconds)
- Can surprise developers
- May conflict with IDE auto-formatting

## 📊 Expected Outcomes

### Immediate Benefits
- ✅ PHPUnit deprecation warnings eliminated
- ✅ Code ready for PHPUnit 12 (when Laravel supports it)
- ✅ Tool installed for future PHP 8.5/9.0 upgrades

### Long-Term Value
- 3-month manual upgrade → 3-day automated (industry data)
- Laravel 12→13→14 upgrades automated
- PHP version migrations simplified

## 🚀 Future Use Cases
1. **PHP Version Upgrades**: `rector process --set=PHP_84`
2. **Laravel Upgrades**: `rector process --set=LARAVEL_110`
3. **Code Quality**: Continuous refactoring to modern patterns

## ⚠️ Important Notes
- Rector output needs formatting (Pint handles this automatically)
- Always run in `--dry-run` mode first on large changes
- Works best with strong test coverage (you have this ✅)
- Rector has PHPStan built-in, so type-safe refactoring

## 💡 Final Recommendation

**Go with Option A (Manual)** because:
- Small codebase (easy to run manually when needed)
- Learning phase (understand what Rector does)
- Already have 4 linters in hooks (adding 5th may slow commits)
- Most valuable for intentional upgrades, not daily commits

---

## 📈 Research Findings

### Key Discovery: ParaTest Bug
Our investigation revealed that `pest --parallel` uses ParaTest under the hood, which has a known bug (ParaTest #911, June 2024) where PHPUnit deprecation warnings are not captured in subprocess output. This means:

- ✅ `phpunit` directly: Returns exit code 1 on deprecations (works)
- ❌ `pest --parallel`: Returns exit code 0 on deprecations (broken)
- ❌ `pest` (non-parallel): Still returns exit code 0 (Pest wrapper issue)

### Alternative Solutions Considered

1. **Remove `--parallel` flag**: Simplest but loses performance benefits
2. **Use test sharding in CI**: Industry best practice (Oh Dear: 16min → 4min)
3. **Rector auto-convert**: Solves root cause (chosen approach)
4. **Upgrade to PHPUnit 12/Pest 4**: Risky, Laravel 12 doesn't officially support PHPUnit 12 yet

### Tool Comparison Research

| Tool | Cost | Best For | When Needed |
|------|------|----------|-------------|
| **Rector** | Free | Everything (PHP/Laravel/PHPUnit) | Now + Future |
| **Laravel Shift** | $9-39/shift or $99/year | Laravel major versions only | Laravel upgrades |
| **PHPStan** | Free | Detection only | Already have ✅ |
| **Manual** | Time | Learning | Not scalable |

### Industry Consensus (2024)
- Rector is essential for PHP 8.4+ projects
- Manual upgrades: 3 months vs Rector: 3 days
- Best practice: Rector + manual review hybrid
- Keep Rector manual for small codebases (<10k LOC)

---

**Estimated Time**: 15 minutes total
**Risk Level**: Low (dry-run first, strong test suite, can revert)
**Value**: High (solves current problem + future-proofs project)

## 📚 References
- [Rector Official Docs](https://getrector.com/)
- [Rector Laravel Rules](https://github.com/driftingly/rector-laravel)
- [PHPUnit 10 Attributes](https://docs.phpunit.de/en/10.5/attributes.html)
- [ParaTest Issue #911](https://github.com/paratestphp/paratest/issues/911)