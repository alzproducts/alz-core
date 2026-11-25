# Service callers authenticate with their own token, with an IP allowlist behind it

Callers that are systems hold no Supabase JWT. Each **service caller** gets its own random 256-bit bearer token and its own IP allowlist, both required, and opens only its own routes.

- **The token is the gate.** Only its SHA-256 is stored, compared with `hash_equals`; a fast hash is enough for a random 256-bit token.
- **The IP allowlist is defence in depth**, checked after the token so a caller without it learns nothing. `IpUtils::checkIp` compares addresses, not text, because one IPv6 address has several text forms.
- **The IP alone is not enough.** A request reaching the origin directly can forge `CF-Connecting-IP` (`SetCloudflareClientIpMiddleware`).
- **Order:** rate limit (429), token (401, one body for every cause), client IP (403). Blank configuration fails closed: a blank hash matches no token and a blank allowlist no IP.

Canonical: `AuthenticateServiceCallerMiddleware`, `config/service-callers.php`, the `api` rate limiter.

## Considered Options

- **IP allowlist alone:** rejected. The header is forgeable.
- **Storing the plain token:** rejected. A leaked environment would expose a usable token.
- **Sanctum:** rejected. It needs a token table and has no IP filter.
- **A custom auth guard:** rejected. Laravel runs it before the throttle.
- **One shared token:** rejected. A leak exposes every route, and rotating it breaks every caller.

## Consequences

- A second caller needs an entry in `config/service-callers.php`, its own environment variables and a route group. The variable names avoid every name the verify stack refuses to set (`CREDENTIAL_PATTERN` and `CREDENTIAL_NAMES` in `.agents/skills/verify/verify`).
