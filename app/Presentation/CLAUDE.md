# Presentation Layer

## Purpose

Catches exceptions **only for delivery mechanism**: HTTP responses, console output. This is "the Laravel stuff" — framework integration.

Jobs live in Application layer (`app/Application/Jobs/`), not Presentation. See `app/Application/CLAUDE.md`.

## Exception Handling

Catch only at the delivery boundary — never to log or suppress.

**Decision tree:**
- **HTTP controller**: let `bootstrap/app.php` global handler map domain exceptions to JSON. Catch only when you need transaction rollback + redirect in the same action.
- **Console command**: catch domain exceptions to render `$this->error(...)` and return `self::FAILURE`.

## Anti-Patterns

- ❌ No business logic in Presentation — delegate entirely to Application use cases
- ❌ Don't catch just to log — Laravel already logs unhandled exceptions
- ❌ No Laravel `FormRequest` — all HTTP input uses Spatie LaravelData DTOs

## Golden Rule

> Presentation speaks Laravel to the framework, business concepts to users.

When neither architecture nor scoped rules give a clear answer, this reframes the decision.

## Per-File Conventions

See `.claude/rules/` for file-type-specific rules (controllers, request DTOs, API resources, middleware, console commands).
