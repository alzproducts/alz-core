# Auth smoke

An authenticated API client calls `GET /api/user` and gets back its own user id and email.

## Sub-features

- `auth-user` returns the authenticated user's `user_id` and `email`.
- `auth-reject` refuses a request without valid authentication.

## How to get to it (user POV)

- Any signed-in dashboard or storefront session calls `GET /api/user` with its bearer token.
- Locally, the `X-Local-Bypass` header stands in for the token and resolves the configured persona.

## Driving it with verify.sh

Preconditions:

- `launch` printed `base_url=` and `doctor` exits 0.

- **Authenticated user.** Run `verify.sh http --evidence <dir> GET /api/user`. Exit 0, `status=200`, and the body has a `user_id` and the `email` `verify@example.invalid` (the run's `EMAIL_PRIMARY`).
- **Proof.** The `http/` capture of that call and its `run-notes.md` line.

## Gotchas

- `verify.sh http` always sends the bypass header, so `auth-reject` is not drivable with it; prove it with a targeted Pest file through `verify.sh test` instead.
- The returned email comes from `EMAIL_PRIMARY`; `dev@alzadmin.test` only selects the persona.
- Rejections on authenticated routes (401, 403) appear in `logs/octane.log`, not Laravel's log.
