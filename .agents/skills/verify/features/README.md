# alz-core API verification map

The maintained source for verifying alz-core's HTTP API. Read this index, then use the matching feature file as the recipe.

## Baseline preconditions

- A run launched with `verify.sh launch` from the worktree under test; `doctor` exits 0 on its evidence dir.
- Data comes only from `dev:seed-fake` (products slice); brands, orders and customers stay empty (ADR-0013).

## Driving conventions

- Drive every journey with `verify.sh http` and `verify.sh db` against the launched run; never against port 8000/8001.
- Journeys that write to an external API are not yet coverable; each feature's `Gotchas` names them.

## Feature entry contract

Each feature file has an H1 title, one paragraph on the user-visible behaviour, then four H2s in order: `Sub-features`, `How to get to it (user POV)`, `Driving it with verify.sh` (opening with `Preconditions:`), `Gotchas`.

## Features

- [Auth smoke](./auth-smoke.md): the authenticated-user endpoint resolves the bypass persona.
- [Products](./products.md): product list and product detail reads.
- [Categories](./categories.md): category list and category detail reads.
