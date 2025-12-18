# Implementation Log: Issue #78 - Layer-Specific Testing Policies

**Issue**: feat: Implement layer-specific testing policies with differentiated coverage targets
**Branch**: `feature/78-feat-implement-layer-specific-testing-policies-with-differentiated-coverage-targets`
**Started**: 2025-12-19

## Context

The codebase currently has uniform 80% coverage across all layers. Clean Architecture layers have different testing value - Domain logic needs rigorous testing while Infrastructure/Presentation (glue code) provide lower ROI.

## Decision Log

| Decision | Rationale |
|----------|-----------|
| Domain 90%+ coverage, 85%+ MSI | Highest business logic density, zero framework deps |
| App Services/Transformers 70%+ coverage/MSI | Real business logic worth mutation testing |
| Exclude UseCases/Jobs from mutation | Orchestration code, minimal testable logic |
| No Infra/Presentation test suites | No coverage targets per Testing_Strategy.md |
| Parallel CI mutation jobs | Faster feedback, net-neutral job count |
| Codecov components (not flags) | Components for code areas, flags for test types |
| Delete unused hook classes | Dead code cleanup - git history preserves |

## Files Modified

- [ ] `phpunit.xml` - Add Domain + Application test suites
- [ ] `Makefile` - Add layer-specific targets
- [ ] `composer.json` - Add delegating scripts
- [ ] `infection.json5` - Narrow to Domain + App/Services + App/Transformers
- [ ] `codecov.yml` - Add component-level coverage
- [ ] `.github/workflows/ci.yml` - Layer-specific mutation jobs
- [ ] `config/git-hooks.php` - Remove dead imports/comments
- [ ] `CLAUDE.md` - Add testing strategy section
- [ ] `tests/CLAUDE.md` - Add layer reference

## Files Deleted

- [ ] `app/DevTools/GitHooks/InfectionPrePushHook.php`
- [ ] `app/DevTools/GitHooks/PestMutatePrePushHook.php`

## Verification Checklist

- [ ] `make test-domain` runs Domain tests
- [ ] `make test-domain-coverage` enforces 90% threshold
- [ ] `make test-app` runs Application tests
- [ ] `make test-app-coverage` enforces 70% threshold
- [ ] `make infection-domain` runs with 85% MSI threshold
- [ ] `make infection-app` runs with 70% MSI threshold
- [ ] CI workflow YAML is valid
- [ ] `make lint` passes

## PR Notes

### Summary

Implement layer-specific testing policies per `tests/TestingStrategy.md`:
- Domain: 90%+ coverage, 85%+ MSI (strict)
- Application Services/Transformers: 70%+ coverage, 70%+ MSI
- Infrastructure/Presentation: No mutation testing (low ROI)

### Changes

- Add `Domain` and `Application` PHPUnit test suites
- Narrow Infection scope to Domain + App/Services + App/Transformers
- Add Makefile targets: `test-domain`, `test-domain-coverage`, `test-app`, `test-app-coverage`, `infection-domain`, `infection-app`
- Update CI with parallel layer-specific mutation jobs
- Add Codecov component-level reporting
- Dead code cleanup: remove unused mutation hook classes

### Test Plan

- [ ] Run `make test-domain-coverage` locally
- [ ] Run `make test-app-coverage` locally
- [ ] Run `make infection-domain` locally
- [ ] Verify CI runs both mutation jobs on PR