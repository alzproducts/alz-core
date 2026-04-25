# Implementation Log: Infrastructure Layer Scoped Rules Migration

**GitHub Issue**: #647
**Plan Document**: .ai/plans/2026-04-25_647-infrastructure-rules-migration.md
**Status**: In Progress
**Started**: 2026-04-25
**Completed**: —

## Overview

Migrates file-shape conventions from `app/Infrastructure/CLAUDE.md` into five path-scoped `.claude/rules/` files. Follows the same pattern as the Eloquent (#642) and Presentation (#644/#645) migrations. Two-stage: Stage 1 corrects 13 drift items in place; Stage 2 creates scoped rules and trims CLAUDE.md to pointers.

## Decision Log

### 2026-04-25

- **Decision**: Two-stage implementation (correct drift first, extract second)
- **Why**: Reviewer can audit each correction against canonical code before seeing a re-homing diff
- **Tradeoff**: Two commits instead of one; cleaner review at cost of slightly more work

- **Decision**: No tables, no specific exception class names, no signature examples in rules
- **Why**: Rules that duplicate code contents rot. Point at canonical classes instead.
- **Tradeoff**: Rules are less self-contained but stay accurate as code evolves

- **Decision**: Exclude `Logging*Transport.php` from http-transports glob
- **Why**: Logging decorators delegate to inner transport; they don't translate exceptions themselves — rule would misfire
- **Tradeoff**: Requires negation pattern in frontmatter

- **Decision**: Promote `DomainConvertibleInterface` from `Shopwired/CLAUDE.md` into `infrastructure-response-dtos.md`
- **Why**: Parser traits depend on `toDomain()` on every integration's DTOs, not just Shopwired
- **Tradeoff**: Slight scope increase (adding content not in Infrastructure root CLAUDE.md) but justified as universally applicable

## Stage 1 Drift Corrections Applied

All 13 corrections from plan applied to `app/Infrastructure/CLAUDE.md`:

1. Catch-and-translate lives in transport layer, not client
2. "Log technical details first" — kept verbatim
3. HTTP status→exception table deleted; pointer to canonical transports added
4. Nested catch reframed to `*ResponseParserTrait.php`, not client methods
5. `ValidationException` → `CannotCreateData` (verified in LinnworksResponseParserTrait line 15)
6. CRITICAL log level — kept verbatim
7. Raw response logging removed; replaced with `get_debug_type($response)` pattern (verified in logParsingFailure())
8. `InvalidApiResponseException` throw — kept verbatim
9. `RuntimeException` → `InvalidConfigurationException` (verified in LinnworksClientFactory::requireStringConfig())
10. `SnakeCaseMapper` hardcoded reference → "appropriate mapper; see neighbours"
11. `fromResolved` only → three factory naming patterns documented
12. Full code example deleted; replaced with structural description + canonical pointers
13. Section opening reframed to clarify transport layer responsibility

## Stage 2 Files Created

- `.claude/rules/infrastructure-http-transports.md`
- `.claude/rules/infrastructure-response-parsers.md`
- `.claude/rules/infrastructure-client-factories.md`
- `.claude/rules/infrastructure-requests.md`
- `.claude/rules/infrastructure-response-dtos.md`

## Deviations from Plan

None yet.

## Blockers / Open Questions

None.

## PR Notes

### What
- Correct 13 drifted conventions in `app/Infrastructure/CLAUDE.md` (wrong exception classes, wrong logging pattern, wrong factory responsibility, incomplete translation matrix references)
- Extract the now-accurate rules into 5 path-scoped `.claude/rules/` files, eliminating global token cost for file-shape conventions

### Why
File-shape conventions in `app/Infrastructure/CLAUDE.md` cost tokens on every Claude interaction regardless of which file is being edited. The rules migration pattern (proven by Eloquent #642 and Presentation #644/#645) scopes them to load only when editing matching file types.

### Key Decisions
- Drift corrections committed separately for clean reviewer audit trail
- Rules point at canonical classes (not tables/signatures) to prevent future drift
- Logging*Transport.php excluded from http-transports glob (decorators, not translators)
- DomainConvertibleInterface requirement promoted from Shopwired-scoped to universal rule

### Testing
Documentation-only change. `make lint` and `make test` pass (no PHP code changed).
