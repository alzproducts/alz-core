---
paths:
  - "app/Presentation/Http/**/Middleware/**/*Middleware.php"
---

# Presentation — HTTP Middleware Rules

## Class Shape

- DO declare `final class` with `public function handle(Request $request, Closure $next): Response`; use `final readonly class` when the middleware holds only constructor-injected dependencies. Canonical: `DetectRefreshMiddleware`.
- DO declare middleware ordering constraints in the class docblock when they exist (`MUST run AFTER ValidateSupabaseJwtMiddleware`) — ordering is otherwise silent until a 500 in prod.

## Request Attributes

- DO stash cross-middleware data on `$request->attributes` (e.g. `authenticated_user`, `forceRefresh`); downstream middleware and controllers read via `$request->attributes->get(...)`.

## Rejections

- DO return `new ApiErrorResponseDTO(...)->toJsonResponse()` for rejections, NOT raw `new JsonResponse(['error' => ...])` — guarantees the shared `{"error": {type, message, errors?}}` envelope.
- DO use `hash_equals` for every HMAC / signature / bypass-secret comparison — never `===` or `strcmp`. **Why**: timing-attack hardening.

## Logging

- DO emit security events (auth failures, signature mismatches) via `Log::channel('security')` with structured context: `event`, `path`, `ip`, and where known `user_id`/`email` — these feed separate log retention.
