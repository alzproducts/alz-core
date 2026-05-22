---
paths:
  - "app/**/*Interface.php"
---

# Interfaces — @throws Documentation

- DO declare `@throws ExceptionType` on each interface method for every checked exception any implementation may throw. **Why:** PHPStan cannot verify `@throws` on interface methods — gaps silently propagate up the call chain. Implementations copy the interface `@throws` per the global rule in `CLAUDE.md` Modern PHP Standards.
- DO name interfaces after the capability they expose, not the class that implements them: `BingAdsConversionInterface`, not `BingAdsConversionClientInterface` or `BingAdsConversionServiceInterface`. DO NOT embed implementation-layer nouns (`Client`, `Service`, `Manager`) in the name — they describe HOW, not WHAT, and become wrong when the implementation changes.

Canonical: `App\Application\Contracts\MixpanelClientInterface`.
