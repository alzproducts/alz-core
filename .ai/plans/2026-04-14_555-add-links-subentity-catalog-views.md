# Plan: Add Links Subentity to Catalog Views

## Context

Product, Category, and Brand views currently expose a flat `public string $url` (the storefront URL from ShopWired). We need to:
1. Wrap this in a per-entity **Links** value object as `publicUrl`
2. Add an `editWebsiteUrl` pointing to the ShopWired admin panel
3. Create a `ShopwiredAdminUrlResolver` in Infrastructure to generate admin URLs
4. Prepare a `CustomerLinks` VO and resolver method (not yet wired to a view)

The admin URLs follow the pattern `https://admin.myshopwired.uk/business/manage-ecommerce-{type}/{externalId}`, with customer URLs varying by trade status.

**Breaking API change**: The flat `"url"` field in API responses becomes a nested `"links"` object. The alz-admin frontend will need a corresponding update.

**Scope boundary**: Only the View VOs (`ProductView`, `CategoryView`, `BrandView`) are modified. The parallel write models (`Product`, `Category`, `Brand`) keep their `public string $url` property unchanged — these are used by domain factories, model mappers, Slack notifications, and sync pipelines.

---

## Step 1 — Create Links Value Objects (Domain)

Four new `final readonly class` files, each tailored to its entity:

### `app/Domain/Catalog/Product/ValueObjects/ProductLinks.php`
```php
final readonly class ProductLinks {
    public function __construct(
        public string $publicUrl,
        public string $editWebsiteUrl,
    ) {}
}
```

### `app/Domain/Catalog/Category/ValueObjects/CategoryLinks.php`
```php
final readonly class CategoryLinks {
    public function __construct(
        public string $publicUrl,
        public string $editWebsiteUrl,
    ) {}
}
```

### `app/Domain/Catalog/Brand/ValueObjects/BrandLinks.php`
```php
final readonly class BrandLinks {
    public function __construct(
        public string $publicUrl,
        public string $editWebsiteUrl,
    ) {}
}
```

### `app/Domain/Customer/ValueObjects/CustomerLinks.php`
```php
final readonly class CustomerLinks {
    public function __construct(
        public string $editWebsiteUrl,
    ) {}
}
```
Note: No `publicUrl` — customers aren't publicly browsable on the storefront.

---

## Step 2 — Create ShopwiredAdminUrlResolver (Infrastructure)

### `app/Infrastructure/Shopwired/ShopwiredAdminUrlResolver.php`

Static pure-function utility (no state, per PHP 8.4 conventions):

```php
final class ShopwiredAdminUrlResolver
{
    private const string BASE = 'https://admin.myshopwired.uk/business';

    public static function productEditUrl(int $externalId): string
        → {BASE}/manage-ecommerce-add-product/{id}

    public static function categoryEditUrl(int $externalId): string
        → {BASE}/manage-ecommerce-add-category/{id}

    public static function brandEditUrl(int $externalId): string
        → {BASE}/manage-ecommerce-add-brand/{id}

    public static function customerEditUrl(int $externalId, bool $isTrade): string
        → isTrade: {BASE}/manage-ecommerce-trade-account/{id}
        → else:    {BASE}/manage-ecommerce-customer/{id}
}
```

---

## Step 3 — Update View VOs (Domain)

Replace `public string $url` with the Links VO on each view:

### `ProductView.php` (`app/Domain/Catalog/Product/ValueObjects/`)
- Constructor param: `string $url` → `ProductLinks $links`
- Property: `public string $url` → `public ProductLinks $links`
- Update `@param` docblock

### `CategoryView.php` (`app/Domain/Catalog/Category/ValueObjects/`)
- Constructor param: `string $url` → `CategoryLinks $links`
- Property: `public string $url` → `public CategoryLinks $links`
- Update `@param` docblock

### `BrandView.php` (`app/Domain/Catalog/Brand/ValueObjects/`)
- Constructor param: `string $url` → `BrandLinks $links`
- Property: `public string $url` → `public BrandLinks $links`
- Update `@param` docblock

---

## Step 4 — Update Mappers/Assemblers (Infrastructure)

Each mapper constructs the Links VO using the model's `external_id` and existing `url`:

### `ProductViewAssembler.php` (`app/Infrastructure/Catalog/Product/Mappers/`)
- Line 74: Replace `url: $model->url` with:
  ```php
  links: new ProductLinks(
      publicUrl: $model->url,
      editWebsiteUrl: ShopwiredAdminUrlResolver::productEditUrl($model->external_id),
  ),
  ```

### `CategoryModel::toViewDomain()` (`app/Infrastructure/Shopwired/Models/CategoryModel.php`)
- Line 136: Replace `url: $this->url` with:
  ```php
  links: new CategoryLinks(
      publicUrl: $this->url,
      editWebsiteUrl: ShopwiredAdminUrlResolver::categoryEditUrl($this->external_id),
  ),
  ```

### `BrandModel::toViewDomain()` (`app/Infrastructure/Shopwired/Models/BrandModel.php`)
- Line 125: Replace `url: $this->url` with:
  ```php
  links: new BrandLinks(
      publicUrl: $this->url,
      editWebsiteUrl: ShopwiredAdminUrlResolver::brandEditUrl($this->external_id),
  ),
  ```

---

## Step 5 — Update API Resources (Presentation)

Replace the flat `'url'` key with a nested `'links'` object:

### `ProductResource.php` (`app/Presentation/Http/Api/Resources/`)
- Line 61: `'url' => $product->url` → `'links' => ['public_url' => $product->links->publicUrl, 'edit_website_url' => $product->links->editWebsiteUrl]`

### `CategoryResource.php` (`app/Presentation/Http/Api/Resources/`)
- Line 43: `'url' => $category->url` → `'links' => ['public_url' => $category->links->publicUrl, 'edit_website_url' => $category->links->editWebsiteUrl]`

### `BrandResource.php` (`app/Presentation/Http/Api/Resources/`)
- Similar change to CategoryResource

---

## Step 6 — Update Tests

**Confirmed test to update:**
- `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php:746` — change `'url'` to `'links'` in the expected keys array

**Search for any others** referencing:
- `->url` on ProductView/CategoryView/BrandView (now `->links->publicUrl`)
- `'url' =>` in API response assertions for these entities

---

## Files Summary

| Action | File |
|--------|------|
| **Create** | `app/Domain/Catalog/Product/ValueObjects/ProductLinks.php` |
| **Create** | `app/Domain/Catalog/Category/ValueObjects/CategoryLinks.php` |
| **Create** | `app/Domain/Catalog/Brand/ValueObjects/BrandLinks.php` |
| **Create** | `app/Domain/Customer/ValueObjects/CustomerLinks.php` |
| **Create** | `app/Infrastructure/Shopwired/ShopwiredAdminUrlResolver.php` |
| **Modify** | `app/Domain/Catalog/Product/ValueObjects/ProductView.php` |
| **Modify** | `app/Domain/Catalog/Category/ValueObjects/CategoryView.php` |
| **Modify** | `app/Domain/Catalog/Brand/ValueObjects/BrandView.php` |
| **Modify** | `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` |
| **Modify** | `app/Infrastructure/Shopwired/Models/CategoryModel.php` |
| **Modify** | `app/Infrastructure/Shopwired/Models/BrandModel.php` |
| **Modify** | `app/Presentation/Http/Api/Resources/ProductResource.php` |
| **Modify** | `app/Presentation/Http/Api/Resources/CategoryResource.php` |
| **Modify** | `app/Presentation/Http/Api/Resources/BrandResource.php` |
| **Modify** | Tests referencing `->url` or `'url' =>` on these views |

## Verification

1. `make lint` — PHPStan will catch any remaining `->url` references and type mismatches
2. `make test` — Run full test suite to catch broken assertions
3. Manual: Hit a product/category/brand API endpoint and verify the `links` object contains both URLs with correct ShopWired admin paths
