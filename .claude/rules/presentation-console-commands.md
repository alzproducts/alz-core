---
paths:
  - "app/Presentation/Console/Commands/**/*Command.php"
---

# Presentation — Console Command Rules

## Class Shape

- DO declare the class `final extends Command` with a multi-line `$signature` and a one-line `$description`.
- DO return `self::SUCCESS` / `self::FAILURE` from `handle()` — never `0` / `1` literals.
- DO delegate to a use case; commands are thin parsers + presenters, not business logic.

## Input / Output

- DO catch boundary `ValueError` when parsing an enum option (`FreeDeliveryType::fromString($opt)`) and render it as `$this->error(...)` + valid-values list; DO NOT let enum parse errors bubble as an exception trace.
- DO output results via `$this->info()` / `->warn()` / `->table()` — never raw `echo`.
- DO expose a `--dry-run` option on any command that dispatches jobs or mutates external systems.

## Production Safety

- DO include a "⚠️ PRODUCTION ONLY" block and `railway ssh ...` example in the class docblock when the command writes to live third-party systems. **Why**: local runs against production credentials leave the audit trail in the wrong database — prior incident with `inventory:update-skus`.
