# Implementation Logs

This directory contains implementation logs for active and completed features. These logs capture decisions, deviations, and reasoning during development—the "messy middle" between plan and PR.

## Purpose

1. **Context persistence** — When starting a new conversation, read the relevant implementation log to understand current state, decisions made, and blockers encountered.
2. **PR source material** — The decision log and PR notes section feed directly into PR descriptions.
3. **Historical reference** — Future debugging benefits from knowing *why* decisions were made, not just *what* was implemented.

## When to Create a Log

Create an implementation log when:
- Working on a feature with an associated plan document (`.ai/plans/`)
- The feature involves multiple decisions or non-trivial implementation choices
- Work will span multiple sessions/conversations

**Don't bother** for trivial bug fixes or single-commit changes.

## File Naming

```
issue-{number}-{short-description}.md
```

Examples:
- `issue-42-mixpanel-ad-spend.md`
- `issue-57-quickbooks-oauth.md`

## Keeping Logs Useful

- **Update during work, not after** — Capture decisions when they happen with fresh context
- **Keep entries terse** — Bullet points, not prose
- **Include the "why"** — The code shows *what*, the log explains *why*
- **Note tradeoffs** — What did you give up? What alternatives were rejected?

## After Completion

When a feature is merged:
1. Update status to "Complete" with completion date
2. Fill in the PR Notes section if not already done
3. Leave the file in place (historical reference) or move to `.ai/implementation-logs/archive/`

---

## Template

Use this template when creating a new implementation log:

```markdown
# Implementation Log: [Feature Name]

**GitHub Issue**: #XX
**Plan Document**: .ai/plans/xxx.md (if applicable)
**Status**: In Progress | Complete | On Hold | Abandoned
**Started**: YYYY-MM-DD
**Completed**: —

## Overview

[One or two sentences on what this feature does and why it exists]

## Decision Log

### YYYY-MM-DD
- **Decision**: [What you decided]
- **Why**: [Reasoning behind the decision]
- **Tradeoff**: [What you gave up or alternatives rejected]

### YYYY-MM-DD
- **Decision**: [Another decision]
- **Why**: [Reasoning]

## Deviations from Plan

[Where implementation differs from the original plan and why]

- [Deviation 1]: [Reason]
- [Deviation 2]: [Reason]

## Blockers / Open Questions

- [ ] [Unresolved item or blocker]
- [ ] [Open question that needs answering]
- [x] [Resolved item - keep for history]

## Technical Notes

[Any implementation details worth remembering that don't fit in code comments]

## PR Notes

[Draft PR description - summarize key decisions and changes for the PR]

### What
[Brief description of the change]

### Why
[Business/technical motivation]

### Key Decisions
- [Decision 1 and why]
- [Decision 2 and why]

### Testing
[How this was tested]
```
