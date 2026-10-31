# Categories

An approved staff user lists catalog categories and opens one category's detail, read from alz-core's own database.

## Sub-features

- `categories-list` returns a paginated category list.
- `categories-show` returns one category's detail by id.

## How to get to it (user POV)

- The dashboard's categories screen calls `GET /api/categories`.
- Opening a category calls `GET /api/categories/{categoryId}`.

## Driving it with verify.sh

Preconditions:

- `launch` printed `base_url=` and `doctor` exits 0.
- The seed's categories are present: `verify.sh db --evidence <dir> 'select count(*) from shopwired.categories'` returns more than 0.

- **List.** Run `verify.sh http --evidence <dir> GET /api/categories`. Exit 0, `status=200`, and a non-empty `data` array of categories.
- **Show.** Take the first `data[].id` from the list capture, then run `verify.sh http --evidence <dir> GET /api/categories/<id>`. Exit 0, `status=200`, and `data.id` equals that id.
- **Proof.** The `http/` captures of each call, plus the `db` count.

## Gotchas

- Not yet coverable (they write to ShopWired, whose credentials are blank in a run): `PUT /api/categories/{id}`, `PUT /api/categories/{id}/custom-fields`, `POST /api/categories/refresh`, `POST /api/categories/{id}/refresh`. HTTP fakes that make them coverable are COR-259.
- `GET /api/categories/{id}/custom-fields` on stale custom-field data enqueues a ShopWired sync and still returns an error; the job lands on the `low` queue, which the run's worker (only `default`) never consumes.
- The seed's sale category takes `SHOPWIRED_SALE_CATEGORY_ID` from `.env.example`; `launch --env-override SHOPWIRED_SALE_CATEGORY_ID=0` fails at the seed step.
