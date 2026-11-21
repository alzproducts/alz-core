# Products

An approved staff user lists catalog products page by page and opens one product's detail, all read from alz-core's own database.

## Sub-features

- `products-list` returns a paginated product list.
- `products-show` returns one product's detail by id.

## How to get to it (user POV)

- The dashboard's products screen calls `GET /api/products` (with paging and sort query parameters).
- Opening a product calls `GET /api/products/{productId}`.

## Driving it with verify

Preconditions:

- `up` printed `base_url=` and `doctor` exits 0.
- The seed's products slice is present: `.agents/skills/verify/verify db --evidence <dir> 'select count(*) from shopwired.products'` returns more than 0.

- **List.** Run `.agents/skills/verify/verify http --evidence <dir> GET /api/products`. Exit 0, `status=200`, and a non-empty `data` array of products.
- **Show.** Take the first `data[].id` from the list capture, then run `.agents/skills/verify/verify http --evidence <dir> GET /api/products/<id>`. Exit 0, `status=200`, and `data.id` equals that id.
- **Unknown id.** Run `.agents/skills/verify/verify http --evidence <dir> GET /api/products/999999999 --expect 404`. Exit 0 with `status=404`.
- **Proof.** The `http/` captures of each call, plus the `db` count.

## Gotchas

- Not driven, because they write to ShopWired through the env file's keys: `PUT /api/products/{id}`, `PUT /api/products/{id}/custom-fields`, `PUT /api/products/{id}/prices`, `POST /api/products/refresh`, `POST /api/products/{id}/refresh`, `POST /api/products/{id}/generate-variant-skus`, `POST /api/products/free-delivery`, `PUT /api/products/cost-prices`, `PUT /api/products/inventory`. HTTP fakes that make them coverable are COR-259.
- `GET /api/products/{id}/custom-fields` on stale custom-field data enqueues a ShopWired sync on the `low` queue and still returns an error; Horizon runs that job against ShopWired with the env file's keys.
- The run's data is synthetic: never compare ids, names or prices against production.
