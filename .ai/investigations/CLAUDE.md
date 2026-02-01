# Investigation Reports

Root cause analyses and debugging sessions that don't result in code changes.

## Purpose

Store investigation reports for situations where:
- In-depth technical analysis was performed
- A problem was identified and understood
- No code changes are required (external issue, timing-based fix, user error, etc.)

## When to Create

- Debugging sessions that uncover external service quirks
- Performance investigations with no actionable fix
- Data anomalies traced to third-party behavior
- "Wait and retry" resolutions worth documenting

## Naming Convention

```
{YYYY-MM-DD}_{topic-slug}.md
```

Example: `2026-02-01_mixpanel-soft-delete-deduplication.md`

## Template

```markdown
# {Title}

**Date:** YYYY-MM-DD
**Participants:** [who investigated]
**Status:** Resolved | Monitoring | Waiting

## Summary

One paragraph: what happened, what we found, what we did.

## Symptoms

- What was observed
- Expected vs actual behavior

## Investigation

### Step 1: [Description]
What we checked, what we found.

### Step 2: [Description]
...

## Root Cause

Clear explanation of why this happened.

## Resolution

What fixed it (or why no fix is needed).

## Lessons Learned

- Key takeaways
- Any edge cases to remember

## References

- Links to docs, issues, external resources
```

## Not For

- Bugs requiring code fixes → GitHub Issues
- Implementation decisions → `.ai/implementation-logs/`
- Reusable knowledge → `.ai/docs/guides/`