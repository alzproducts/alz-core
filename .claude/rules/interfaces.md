---
paths:
  - "app/**/*Interface.php"
---

# Interfaces — @throws Documentation

- DO declare `@throws ExceptionType` on each interface method for every checked exception any implementation may throw. **Why:** PHPStan cannot verify `@throws` on interface methods — gaps silently propagate up the call chain. Implementations copy the interface `@throws` per the global rule in `CLAUDE.md` Modern PHP Standards.

Canonical: `App\Application\Contracts\MixpanelClientInterface`.
