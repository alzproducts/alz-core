# Presentation Layer

## Purpose

Catches exceptions **only for delivery mechanism**: HTTP responses, console output. This is "the Laravel stuff" — framework integration.

Jobs live in Application layer (`app/Application/Jobs/`), not Presentation. See `app/Application/CLAUDE.md`.

## Anti-Patterns

- ❌ No business logic in Presentation — delegate entirely to Application use cases
- ❌ Don't catch just to log — Laravel already logs unhandled exceptions

## Directory Organization

**Feature threshold**: Create subdirectory when feature has 2+ related files.

| Location | Contents |
|----------|----------|
| `Http/{Feature}/` | Feature-specific DTOs, mappers, resources |
| `Http/Middleware/` | Global-only middleware |
| `Http/Controllers/{Feature}/` | Feature controllers |

See `.claude/rules/presentation-controllers.md` and `.claude/rules/presentation-commands.md` for file-type-specific rules.
