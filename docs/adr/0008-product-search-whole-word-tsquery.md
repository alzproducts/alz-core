# Product search: whole-word tsquery on the list endpoint, not substring or a search endpoint

- **Whole-word stemmed matching** (`websearch_to_tsquery`), not `pg_trgm` or `ILIKE`: the consumer is a submit-style search box, and it parses arbitrary input without erroring. Typeahead can use prefix matching (`:*`) on the same GIN index (`idx_shopwired_products_title_search_gin`); substring needs a trigram index.
- **A `search` param on `GET /products`**, not a search endpoint: search composes with the existing filters, includes and pagination, and `/products/search` would collide with `products/{productId}`.
- **Implicit relevance ordering:** search with no `sort_by` sorts by `ts_rank`, then `title`, because tied ranks would otherwise repeat or skip rows between pages. An explicit `sort_by` wins. `ProductSortField` has no `relevance` case, since it means nothing without a search term.

Consequence: the GIN index lives on the base table, not the view, so recreating `catalog.products_view` leaves it in place; a migration that rewrites `shopwired.products.title` or the view's `title` column must keep the expression intact. The index only fires when the query repeats the exact expression `to_tsvector('english', title)`; a drifted expression silently degrades to a seq scan.
