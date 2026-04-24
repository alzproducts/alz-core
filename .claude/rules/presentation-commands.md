---
paths:
  - "app/Presentation/Console/Commands/**/*.php"
---

# Console Command Rules

## Exit Codes

- DO return `self::SUCCESS` or `self::FAILURE` from `handle()` — never `void`, never a bare integer literal

## Exception Handling

- DO catch domain exceptions in `handle()` and translate to operator output: `$this->error(message)` + `$this->line("  Check: ...")` for a resolution hint
- DO NOT rethrow or bubble exceptions — unhandled exceptions render as raw stack traces instead of operator guidance
- DO NOT catch just to log — the framework already logs unhandled exceptions
