# Products

An approved staff user lists catalog products page by page and opens one product's detail, all read from alz-core's own database.

## Sub-features

- `products-list` returns a paginated product list.
- `products-show` returns one product's detail by id.

## How to get to it (user POV)

- The dashboard's products screen calls `GET /api/products` (with paging and sort query parameters).
- Opening a product calls `GET /api/products/{productId}`.

## Driving it with verify.sh

Preconditions:

- `launch` printed `base_url=` and `doctor` exits 0.
- The seed's products slice is present: `verify.sh db --evidence <dir> 'select count(*) from shopwired.products'` returns more than 0.

- **List.** Run `verify.sh http --evidence <dir> GET /api/products`. Exit 0, `status=200`, and a non-empty `data` array of products.
- **Show.** Take the first `data[].id` from the list capture, then run `verify.sh http --evidence <dir> GET /api/products/<id>`. Exit 0, `status=200`, and `data.id` equals that id.
- **Unknown id.** Run `verify.sh http --evidence <dir> GET /api/products/999999999`. Exit 1 with `status=404`.
- **Proof.** The `http/` captures of each call, plus the `db` count.

## Gotchas

- Not yet coverable (they write to ShopWired, whose credentials are blank in a run): `PUT /api/products/{id}`, `PUT /api/products/{id}/custom-fields`, `PUT /api/products/{id}/prices`, `POST /api/products/refresh`, `POST /api/products/{id}/refresh`, `POST /api/products/{id}/generate-variant-skus`, `POST /api/products/free-delivery`, `PUT /api/products/cost-prices`, `PUT /api/products/inventory`. HTTP fakes that make them coverable are COR-259.
- `GET /api/products/{id}/custom-fields` on stale custom-field data enqueues a ShopWired sync and still returns an error. The job lands on the `low` queue, which the run's worker (only `default`) never consumes.
- The run's data is synthetic: never compare ids, names or prices against production.
