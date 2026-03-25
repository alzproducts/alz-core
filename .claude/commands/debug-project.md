# Debug Project

**BEFORE DOING ANYTHING**, read these sections in `CLAUDE.md`:
- Development Environment (Quick Reference)
- Local API Testing
- Debugging & Logs
- Queue Processing

## Principles

1. **Run autonomously.** Investigate, diagnose, and fix without asking the user unless truly blocked.
2. **Don't assume — verify.** Every hypothesis must be confirmed via logs or commands before acting on it.

## Workflow

1. Check if Octane is running before sending any HTTP requests
2. Reproduce the issue first, then diagnose
3. Read the relevant log after reproducing — don't guess the cause
4. Use `php artisan tinker` to verify config resolution when unsure
5. Pipe curl through `jq` (not `python3` — blocked by hook)

## Input

$ARGUMENTS