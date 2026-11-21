# Auth smoke

An authenticated API client calls `GET /api/user` and gets back its own user id and email.

## Sub-features

- `auth-user` returns the authenticated user's `user_id` and `email`.
- `auth-reject` refuses a request without valid authentication.

## How to get to it (user POV)

- Any signed-in dashboard or storefront session calls `GET /api/user` with its bearer token.
- Locally, the `X-Local-Bypass` header stands in for the token and resolves the configured persona.

## Driving it with verify

Preconditions:

- `up` printed `base_url=` and `doctor` exits 0.

- **Authenticated user.** Run `.agents/skills/verify/verify http --evidence <dir> GET /api/user`. Exit 0, `status=200`, and the body has a `user_id` and an `email` equal to the env file's `EMAIL_PRIMARY` (`verify@example.invalid` when the file leaves it blank).
- **Proof.** The `http/` capture of that call and its `run-notes.md` line.

## Gotchas

- `http` always sends the bypass header, so `auth-reject` is not drivable with it; prove it with a targeted Pest file through `test` instead.
- The returned email comes from `EMAIL_PRIMARY`; `SUPABASE_LOCAL_TEST_EMAIL` (`dev@alzadmin.test`) only selects the persona.
- Rejections on authenticated routes (401, 403) reach the app container's stdout (`logs app`), not Laravel's log.
