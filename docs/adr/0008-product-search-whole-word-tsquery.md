# Product search: whole-word tsquery on the list endpoint, not substring or a search endpoint

The products list API gains free-text search via `websearch_to_tsquery('english', ?)` against `to_tsvector('english', title)`, backed by an expression GIN index on `shopwired.products` (`idx_shopwired_products_title_search_gin`), which the live `catalog.products_view` reads from.

Three deliberate choices a future reader might otherwise "fix":

1. **Whole-word stemmed matching, not substring.** "lamp" finds "lamps" (English stemmer) but "lam" finds nothing. We rejected `pg_trgm`/`ILIKE '%term%'` substring search: the consumer is a submit-style search box, not live typeahead, and `websearch_to_tsquery` safely parses arbitrary user input (quotes, operators) without erroring. If typeahead is ever needed, prefix matching (`:*` on the last term) works against the same GIN index — substring semantics would require a different (trigram) index entirely.
2. **A `search` param on `GET /products`, not a `GET /products/search` endpoint.** Search returns the same `ProductResource` shape and must compose with the existing filters (`category_id`, `is_active`, `sku`), includes, and pagination. A separate endpoint would duplicate that surface or ship without it, and would collide with the `products/{productId}` wildcard route.
3. **Implicit relevance ordering.** Search present + no `sort_by` → `ts_rank` descending with `title asc` tiebreak (short titles tie heavily on rank; without the tiebreak, tied rows have no stable order and pagination can repeat or skip them between page requests). An explicit `sort_by` always wins. No `relevance` case is added to `ProductSortField` — relevance is not orderable without a search term, so exposing it would need a cross-field validation rule for no gain.

Consequence: the GIN index lives on the base table, not the view, so recreating `catalog.products_view` leaves it in place; a migration that rewrites `shopwired.products.title` or the view's `title` column must keep the expression intact. The index only fires when the query repeats the exact expression `to_tsvector('english', title)`; a drifted expression silently degrades to a seq scan.
