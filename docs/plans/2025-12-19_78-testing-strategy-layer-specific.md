# Testing Strategy Implementation Plan

**Goal**: Implement layer-specific testing policies from `tests/Testing_Strategy.md`

**Scope**: Configuration changes and documentation updates only. Defer test deletion/reorganization.

---

## Summary of Changes

| Layer | Coverage Target | Mutation Testing | Current State |
|-------|-----------------|------------------|---------------|
| Domain | 90%+ | MSI 85%+ | Global 80% |
| Application | 70%+ | Services/Transformers only (70%+) | Global 80% |
| Infrastructure | None | Skip | Global 80% |
| Presentation | None | Skip | Global 80% |

---

## Phase 1: PHPUnit Layer-Specific Test Suites

**File**: `phpunit.xml`

Add layer-specific test suites for Domain and Application only (Infra/Presentation have no coverage targets):

```xml
<testsuites>
    <!-- Existing -->
    <testsuite name="Unit">...</testsuite>
    <testsuite name="Feature">...</testsuite>
    <testsuite name="Architecture">...</testsuite>

    <!-- New layer-specific suites -->
    <testsuite name="Domain">
        <directory>tests/Unit/Domain</directory>
    </testsuite>
    <testsuite name="Application">
        <directory>tests/Unit/Application</directory>
        <directory>tests/Feature/Application</directory>
    </testsuite>
</testsuites>
```

**Note**: Infrastructure/Presentation test suites NOT added - they have no coverage or mutation targets per Testing_Strategy.md.

---

## Phase 2: Infection Configuration

**File**: `infection.json5`

Update to focus mutation testing on Domain + Application Services/Transformers only:

```json5
{
    "source": {
        "directories": [
            "app/Domain",                    // 85%+ MSI (strict)
            "app/Application/Services",      // 70%+ MSI
            "app/Application/Transformers"   // 70%+ MSI
        ]
        // Removed: app/Infrastructure, app/Presentation
        // Excluded: app/Application/Jobs, app/Application/UseCases (per Testing_Strategy.md)
    },
    "minMsi": 70,           // Lower global threshold
    "minCoveredMsi": 80     // Keep covered threshold
}
```

**Rationale**:
- Infrastructure/Presentation mutation testing provides low ROI (glue code)
- UseCases are orchestration with minimal testable logic
- Services/Transformers contain real business logic worth mutation testing

---

## Phase 3: Makefile Layer-Specific Targets

**File**: `Makefile`

**Step 1**: Add to `.PHONY` declaration (line 1):
```makefile
.PHONY: ... test-domain test-domain-coverage test-app test-app-coverage infection-domain infection-app
```

**Step 2**: Add new targets:

```makefile
# Layer-specific test targets
test-domain: ## Run Domain layer tests (90%+ coverage target)
	$(EXEC) vendor/bin/pest --testsuite=Domain --parallel --fail-on-deprecation

test-domain-coverage: ## Run Domain tests with 90% coverage requirement
	$(EXEC) -d xdebug.mode=coverage vendor/bin/pest --testsuite=Domain --coverage --min=90

test-app: ## Run Application layer tests (70%+ coverage target)
	$(EXEC) vendor/bin/pest --testsuite=Application --parallel --fail-on-deprecation

test-app-coverage: ## Run Application tests with 70% coverage requirement
	$(EXEC) -d xdebug.mode=coverage vendor/bin/pest --testsuite=Application --coverage --min=70

# Layer-specific mutation testing
infection-domain: ## Run Infection on Domain layer only (85%+ MSI)
	$(EXEC) -d xdebug.mode=off vendor/bin/infection \
		--filter=app/Domain --min-msi=85 --min-covered-msi=90 \
		--no-progress --show-mutations --test-framework-options="--testsuite=Domain"

infection-app: ## Run Infection on Application Services/Transformers (70%+ MSI)
	$(EXEC) -d xdebug.mode=off vendor/bin/infection \
		--filter="app/Application/(Services|Transformers)" \
		--min-msi=70 --min-covered-msi=80 \
		--no-progress --show-mutations --test-framework-options="--testsuite=Application"
```

**Note**: `infection-app` uses regex alternation `(Services|Transformers)` to match both directories in a single filter.

---

## Phase 4: Codecov Layer-Specific Coverage

**File**: `codecov.yml`

Add component-level coverage (NOT flags - components are for code area visibility):

```yaml
coverage:
  status:
    project:
      default:
        target: 75%  # Overall (lower since Infra/Presentation have no targets)
    patch:
      default:
        target: 70%

# Components show coverage by code area in Codecov dashboard
component_management:
  individual_components:
    - component_id: domain
      name: Domain Layer
      paths:
        - app/Domain/**
    - component_id: application
      name: Application Layer
      paths:
        - app/Application/**
```

**Note**: The existing CI coverage upload (`flags: backend`) remains unchanged - flags are for test run types, not code areas.

---

## Phase 5: CI Workflow Updates

**File**: `.github/workflows/ci.yml`

**Approach**: Separate parallel jobs per layer (faster, better feedback)

Replace existing `mutation-infection` and `mutation-pest` jobs with layer-specific jobs:

```yaml
mutation-domain:
  name: Mutation Testing (Domain - 85%+ MSI)
  runs-on: ubuntu-24.04
  timeout-minutes: 20
  if: github.base_ref == 'develop'
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.4'
        coverage: pcov  # REQUIRED for Infection
        extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_pgsql, bcmath, intl, redis
    - name: Install dependencies
      run: composer install --no-interaction --prefer-dist
    - name: Prepare Laravel
      run: |
        cp .env.example .env
        php artisan key:generate
    - name: Run Infection on Domain
      run: make infection-domain

mutation-application:
  name: Mutation Testing (Application - 70%+ MSI)
  runs-on: ubuntu-24.04
  timeout-minutes: 20
  if: github.base_ref == 'develop'
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.4'
        coverage: pcov  # REQUIRED for Infection
        extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_pgsql, bcmath, intl, redis
    - name: Install dependencies
      run: composer install --no-interaction --prefer-dist
    - name: Prepare Laravel
      run: |
        cp .env.example .env
        php artisan key:generate
    - name: Run Infection on Application
      run: make infection-app
```

**Key points**:
- Both jobs need `coverage: pcov` in PHP setup (Infection auto-detects it)
- Remove existing `mutation-pest` and `mutation-infection` jobs
- `if: github.base_ref == 'develop'` — Mutation testing only on PRs to develop (feature branch workflow: feature → develop → main; new code enters via develop PRs)
- Jobs run in parallel for faster feedback

---

## Phase 6: Documentation Updates

### 6.1 Root CLAUDE.md

**Location**: After "### Test Generation with zen:testgen" section

Add new section:

```markdown
## Testing Strategy by Layer

**Reference**: See `tests/Testing_Strategy.md` for full guidance.

| Layer | Coverage | Mutation | Test Type |
|-------|----------|----------|-----------|
| Domain | 90%+ | MSI 85%+ | Unit (no mocks) |
| Application | 70%+ | Services only (70%+) | Unit + Integration |
| Infrastructure | — | — | Integration only |
| Presentation | — | — | Feature/smoke |

**Commands**:
- `make test-domain-coverage` — Domain with 90% threshold
- `make test-app-coverage` — Application with 70% threshold
- `make infection-domain` — Domain mutation testing (85%+ MSI)
- `make infection-app` — Application Services/Transformers mutation testing (70%+ MSI)
```

### 6.2 tests/CLAUDE.md

Update "## Code Coverage Strategy" section to reference layer-specific targets.

Add reference at top:
```markdown
**For layer-specific policies, see `tests/Testing_Strategy.md`.**
```

### 6.3 Commit Testing_Strategy.md

The file exists but is untracked. Add to version control.

---

## Phase 7: Verification

1. **Run current coverage** to establish baseline:
   ```bash
   make test-domain-coverage  # Check if 90% passes
   make test-app-coverage     # Check if 70% passes
   ```

2. **Run layer mutation testing**:
   ```bash
   make infection-domain      # Check if 85% MSI passes
   make infection-app         # Check if 70% MSI passes
   ```

3. **If thresholds fail**:
   - **Coverage failure**: Add focused unit tests for uncovered Domain/Application code
   - **MSI failure**: Strengthen existing test assertions (avoid assertNotNull → use assertEquals)
   - **Scope limit**: If more than ~10 new tests needed, use fallback thresholds below and iterate

4. **Fallback thresholds** (if initial targets too aggressive):

   | Layer | Initial | Fallback | Notes |
   |-------|---------|----------|-------|
   | Domain Coverage | 90% | 80% | Match current global |
   | Domain MSI | 85% | 70% | Still meaningful |
   | App Coverage | 70% | 60% | Minimal bar |
   | App MSI | 70% | 60% | Minimal bar |

   **Process**: Try initial → if fail with >10 tests gap → use fallback → create follow-up issue

5. **Not a blocker**: Config changes can merge; threshold iteration happens in follow-up PRs

---

## Files to Modify

| File | Change |
|------|--------|
| `phpunit.xml` | Add Domain + Application test suites (not Infra/Presentation) |
| `infection.json5` | Narrow to Domain + App/Services + App/Transformers only |
| `Makefile` | Add layer-specific targets + update .PHONY |
| `composer.json` | Add scripts delegating to new Makefile targets |
| `codecov.yml` | Add component-level coverage (Domain, Application) |
| `.github/workflows/ci.yml` | Replace mutation jobs with layer-specific jobs |
| `config/git-hooks.php` | Remove dead mutation hook imports and comments |
| `app/DevTools/GitHooks/InfectionPrePushHook.php` | **DELETE** - unused hook class |
| `app/DevTools/GitHooks/PestMutatePrePushHook.php` | **DELETE** - unused hook class |
| `CLAUDE.md` | Add testing strategy section after zen:testgen section |
| `tests/CLAUDE.md` | Update coverage strategy, add reference to Testing_Strategy.md |
| `tests/Testing_Strategy.md` | Commit to version control (no content changes needed) |

---

## Phase 8: Dead Code Cleanup

**Files to remove**:
- `app/DevTools/GitHooks/InfectionPrePushHook.php`
- `app/DevTools/GitHooks/PestMutatePrePushHook.php`

**File to update**: `config/git-hooks.php`

Remove unused mutation testing hook imports and commented entries:

```php
// REMOVE these imports (lines 5-6):
use App\DevTools\GitHooks\InfectionPrePushHook;
use App\DevTools\GitHooks\PestMutatePrePushHook;

// REMOVE these commented lines from 'pre-push' array (lines 44-45):
// PestMutatePrePushHook::class,  // Moved to CI - runs in parallel
// InfectionPrePushHook::class,   // Moved to CI - runs in parallel
```

**Rationale**: Dead code clutters the codebase. Git history preserves it if ever needed.

---

## Implementation Order

1. **phpunit.xml** — Foundation for layer-specific test runs
2. **Makefile + composer.json** — Commands to exercise new test suites
3. **Verify thresholds pass** — Run `make test-domain-coverage` etc.
4. **infection.json5** — Update mutation testing scope
5. **Verify mutation thresholds** — Run `make infection-domain` etc.
6. **codecov.yml** — Layer-specific coverage reporting
7. **CI workflow** — Update GitHub Actions
8. **Dead code cleanup** — Delete unused hook files, clean config
9. **Documentation** — Update CLAUDE.md files
10. **Commit Testing_Strategy.md** — Add to version control
11. **Final verification** — Full `make check` pass

---

## Decisions Made

| Question | Decision |
|----------|----------|
| CI approach | Separate parallel jobs per layer |
| Codecov | Components for code area visibility (not flags) |
| Git hooks | No changes - mutation testing already in CI only |
| Application scope | **Strict**: Services + Transformers only (per Testing_Strategy.md) |
| Infra/Presentation suites | Not created (no coverage/mutation targets) |

---

## Potential Risks

1. **Domain 90% threshold may not pass initially** — May need tests or fallback thresholds
2. **Codecov component setup** — First-time config, may need iteration
3. **CI job count** — Net neutral (2 mutation jobs → 2 layer-specific jobs)
