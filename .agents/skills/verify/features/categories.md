# Categories

An approved staff user lists catalog categories and opens one category's detail, read from alz-core's own database.

## Sub-features

- `categories-list` returns a paginated category list.
- `categories-show` returns one category's detail by id.

## How to get to it (user POV)

- The dashboard's categories screen calls `GET /api/categories`.
- Opening a category calls `GET /api/categories/{categoryId}`.

## Driving it with verify

Preconditions:

- `up` printed `base_url=` and `doctor` exits 0.
- The seed's categories are present: `.agents/skills/verify/verify db --evidence <dir> 'select count(*) from shopwired.categories'` returns more than 0.

- **List.** Run `.agents/skills/verify/verify http --evidence <dir> GET /api/categories`. Exit 0, `status=200`, and a non-empty `data` array of categories.
- **Show.** Take the first `data[].id` from the list capture, then run `.agents/skills/verify/verify http --evidence <dir> GET /api/categories/<id>`. Exit 0, `status=200`, and `data.id` equals that id.
- **Proof.** The `http/` captures of each call, plus the `db` count.

## Gotchas

- Not driven, because they write to ShopWired through the env file's keys: `PUT /api/categories/{id}`, `PUT /api/categories/{id}/custom-fields`, `POST /api/categories/refresh`, `POST /api/categories/{id}/refresh`. HTTP fakes that make them coverable are COR-259.
- `GET /api/categories/{id}/custom-fields` on stale custom-field data enqueues a ShopWired sync on the `low` queue and still returns an error; Horizon runs that job against ShopWired with the env file's keys.
- The stack sets `SHOPWIRED_SALE_CATEGORY_ID=2000000001`, the seed's own sale slot, whatever the env file says; `up` refuses an override of it (exit 64).
