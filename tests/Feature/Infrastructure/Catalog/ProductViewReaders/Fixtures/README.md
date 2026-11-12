# Product view readers: golden files

`ProductViewReadersParityTest` compares what the readers of `catalog.products_view` and
`catalog.product_variations_view` (other than the products list) return on its own fixtures
against these files. They were captured at `1d5f69683439c5e7dec651511b9f8d339a33f95a`, the commit
the products-list rework started from, where `products_view` was still a materialized view.

Only the variation order in `detail-product-views.json` is normalised (sorted by id): the detail
query loads variations unordered. No values are masked; every fixture row has fixed ids and
timestamps.

## Regenerating

Regenerate only when a reader's output is meant to change, and review the diff.

1. Check out the starting commit, or the commit whose output should become the baseline, in a
   throwaway worktree: `git worktree add --detach .claude/worktrees/<name> <sha>`.
2. In it, run `composer install`, then `cp .env.example .env` and `php artisan key:generate`.
3. Start an empty Postgres 17, export the CI test env (`APP_ENV=testing`, the `DB_*` values
   pointing at it, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`) and run
   `php artisan migrate --force`.
4. Copy `ProductViewReadersParityTest.php` into the same path there and run it with
   `PRODUCT_VIEW_READERS_CAPTURE=1 php artisan test tests/Feature/Infrastructure/Catalog/ProductViewReaders`.
   It refreshes `products_view` itself when that view is materialized.
5. Run it again without the variable to confirm the capture is deterministic. Then copy the JSON
   files back here and remove the throwaway worktree with `git worktree remove --force`.
