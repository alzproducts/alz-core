# Presentation Layer

## Purpose
Catches exceptions **only for delivery mechanism**: HTTP responses, console output. This is "the Laravel stuff" — framework integration.

Jobs live in Application layer (`app/Application/Jobs/`), not Presentation. See `app/Application/CLAUDE.md`.

## Controllers: Global Exception Handler (Preferred)

Register exception-to-HTTP mappings in `bootstrap/app.php`. Controllers stay clean with no try-catch — the global handler converts domain exceptions to HTTP responses.

Only catch in a controller when you need transaction rollback + redirect that the global handler can't provide.

## Commands: User-Friendly Output

Catch domain exceptions in commands for:
- User-friendly error messages (`$this->error()`)
- Appropriate exit codes (`self::SUCCESS` / `self::FAILURE`)
- Guidance toward resolution

## Anti-Patterns

- ❌ Don't catch just to log — Laravel already logs unhandled exceptions
- ❌ Don't duplicate global handler logic in controllers — if `bootstrap/app.php` already handles the exception, let it
- ❌ No business logic in Presentation

## Decision Tree
```
Exception reaches Presentation
    ↓
Is this a Controller?
    → Can global handler handle it? → Don't catch
    → Need transaction rollback + redirect? → Catch in controller

Is this a Command?
    → Catch for user-friendly output + exit codes
```

## Directory Organization

**Feature threshold**: Create subdirectory when feature has 2+ related files.

| Location | Contents |
|----------|----------|
| `Http/{Feature}/` | Feature-specific middleware, resources |
| `Http/Middleware/` | Global-only middleware |
| `Http/Controllers/{Feature}/` | Feature controllers |

## Naming

- Controllers: Multi-action `{Feature}Controller`, single-action invokable (`__invoke`)

**Golden Rule**: Presentation speaks Laravel to framework, business concepts to users.
