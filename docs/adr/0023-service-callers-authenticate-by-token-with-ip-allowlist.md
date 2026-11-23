# Service callers authenticate with their own token, with an IP allowlist behind it

Some callers of alz-core are systems, not staff: they hold no Supabase JWT. Each **service caller** gets its own random 256-bit bearer token and its own IP allowlist, and both are required. A credential opens only that caller's routes.

- **The token is the gate.** alz-core's environment holds only the token's SHA-256, compared through `hash_equals`. A fast hash is enough because the token is random and 256 bits long.
- **The IP allowlist is defence in depth.** It is checked after the token, so a caller without the token learns nothing about it. `IpUtils::checkIp` compares addresses, not their text, because one IPv6 address has several text forms; an entry is an address or a CIDR range.
- **The IP alone is not enough.** The client IP comes from `CF-Connecting-IP`, and a request that reaches the origin directly can forge that header (`SetCloudflareClientIpMiddleware`).
- **One credential per named caller.** A leaked token exposes one caller's routes and is rotated alone.

The checks run in this order: rate limit (429), token (401, one body for every cause), client IP (403). Blank configuration has no check of its own and does not stop the app: a blank hash matches no token (401), and a blank allowlist matches no IP (403).

Canonical: `AuthenticateServiceCallerMiddleware`, `config/service-callers.php`, the `api` rate limiter.

## Considered Options

- **IP allowlist alone:** rejected. The header it depends on is forgeable.
- **Store the plain token in alz-core:** rejected. The hash is enough to verify it, and a leaked environment then exposes no usable token.
- **Sanctum:** rejected. It needs a token table and has no IP filter.
- **A custom auth guard:** rejected. Laravel runs it before the throttle.
- **One token shared by all service callers:** rejected. A leak would expose every route, and rotating it would break every caller.

## Consequences

- A second caller needs an entry in `config/service-callers.php`, its own pair of environment variables, and a route group using `AuthenticateServiceCallerMiddleware::using('<caller>')`.
- The variable names carry the caller's name and avoid every name the verify stack refuses to set (`CREDENTIAL_PATTERN` and `CREDENTIAL_NAMES` in `.agents/skills/verify/verify`), so the auth can be proven at runtime there.
- A caller whose IP changes is refused with 403 until its allowlist is updated.
