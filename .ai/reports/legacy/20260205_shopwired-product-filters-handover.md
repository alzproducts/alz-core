# ShopWired Product Filters - Legacy Feature Handover

**Date:** 2026-02-05
**Feature:** ShopWired Product Filters System
**Source Codebase:** `/Users/tom/code/alz-connect`

---

## 1. Feature Overview

The ShopWired Product Filters system allows products to be tagged with filter values that enable faceted navigation on the e-commerce frontend. Filters are organised into filter groups (e.g., "Size", "Colour", "VAT Relief Eligible") and products can have multiple values assigned per filter group.

Key characteristic: **Filters are NOT documented in the official ShopWired API documentation** but are fully functional via the API using the embed mechanism.

---

## 2. Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         ShopWired API                               │
├─────────────────────────────────────────────────────────────────────┤
│  GET /products/{id}?embed=filters     → Returns product with filters│
│  GET /filter-groups                   → Returns all filter groups   │
│  POST /products                       → Create product with filters │
│  PUT /products/{id}                   → Update product filters      │
└─────────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    Legacy PHP Application                           │
├─────────────────────────────────────────────────────────────────────┤
│  ShopWiredA                    │  Main API client class             │
│  ProductEndpoint               │  Product-specific API operations   │
│  Product (Model)               │  Product entity with filter prop   │
│  Filter (Model)                │  Individual filter entity          │
│  FormatFiltersPost             │  Form submission → API format      │
│  FilterGroup (Form)            │  UI filter group builder           │
└─────────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    Local MySQL Cache                                │
├─────────────────────────────────────────────────────────────────────┤
│  filter_groups      │  id, title, optionNo                          │
│  products_filters   │  fkId, optionNo, value                        │
│  category_filters   │  fkFilterId, fkCatId                          │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 3. External Integrations

### ShopWired API

**Base URL:** Configured via RestClient (environment-specific)

**Authentication:** API key-based (handled by RestClient class)

### Endpoints

#### Get Product with Filters
```
GET /products/{id}?embed=filters
```

**Full embed string (includes filters):**
```
embed=images,brand,categories,related,extras,customization_fields,ebay_shipping_rates,options,product_option_values,vat_relief,digital_files,choices,filters,custom_fields,variations
```

#### Get Filter Groups
```
GET /filter-groups
```
Returns all available filter group definitions.

#### Create Product with Filters
```
POST /products
Content-Type: application/json

{
  "title": "Product Name",
  "price": 19.99,
  "filters": {
    "1": ["Small", "Medium", "Large"],
    "2": ["Yes"]
  },
  ...other fields
}
```

#### Update Product Filters
```
PUT /products/{id}
Content-Type: application/json

{
  "filters": {
    "1": ["Small", "Large"],
    "2": ["Yes"]
  }
}
```

---

## 4. Data Structures

### API Response Format - Product with Filters

Filters are returned as a **JSON object** (stdClass in PHP) where:
- **Keys** = `optionNo` (filter group identifier, string representation of integer)
- **Values** = Array of string values

```json
{
  "id": 12345,
  "title": "Example Product",
  "filters": {
    "1": ["Small", "Medium"],
    "3": ["Blue", "Red"],
    "2": ["Yes"]
  },
  ...other product fields
}
```

### API Response Format - Filter Groups

```json
[
  {
    "id": 1,
    "title": "Size",
    "optionNo": 1
  },
  {
    "id": 2,
    "title": "VAT Relief Eligible",
    "optionNo": 2
  },
  {
    "id": 3,
    "title": "Colour",
    "optionNo": 3
  }
]
```

### API Request Format - Creating/Updating Filters

```json
{
  "filters": {
    "optionNo": ["value1", "value2"],
    "optionNo2": ["value3"]
  }
}
```

**Important:** The `optionNo` keys are strings in JSON but represent integers. The API accepts both string and integer keys.

---

## 5. Code Inventory

### Core Classes

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/ShopWired/src/Products/Product.php` | `Product` | Main product entity with `$filters` property |
| `legacy/src/ShopWired/src/Products/Filter.php` | `Filter` | Individual filter entity (index + values) |
| `legacy/src/ShopWired/src/Products/Funct/ProductFunct.php` | `ProductFunct` | Contains `allEmbeds()` method with filters |
| `legacy/src/ShopWired/src/ShopWiredA.php` | `ShopWiredA` | Main API client, includes `filterGroupsGet()` |
| `legacy/src/ShopWired/src/Endpoint/ProductEndpoint.php` | `ProductEndpoint` | Product API operations |
| `legacy/src/ShopWired/src/Endpoint/AbstractEndpoint.php` | `AbstractEndpoint` | Base endpoint with query string builder |

### Form Handling Classes

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/NewProduct/Form/Submit/ShopWired/Filters/FormatFiltersPost.php` | `FormatFiltersPost` | Converts POST data to API filter format |
| `legacy/src/NewProduct/Form/Submit/ShopWired/Filters/FormatOneFilter.php` | `FormatOneFilter` | Formats individual filter from POST |
| `legacy/src/NewProduct/Form/FieldGroup/FilterGroup.php` | `FilterGroup` | Creates filter form fields |
| `legacy/src/NewProduct/Form/Field/Filter/Filter.php` | `Filter` | Individual filter form field |
| `legacy/src/NewProduct/Form/Field/Filter/SelectPickerFilter.php` | `SelectPickerFilter` | Renders filter dropdown UI |
| `legacy/src/NewProduct/Form/Section/Filters/FilterSection.php` | `FilterSection` | Filter section in product form |

### Product Creation Classes

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/NewProduct/Form/Submit/ShopWired/CreateSwProductMain.php` | `CreateSwProductMain` | Orchestrates product creation |
| `legacy/src/NewProduct/Form/Submit/ShopWired/CreateSwProductModel.php` | `CreateSwProductModel` | Creates product via API |
| `legacy/src/NewProduct/Form/Submit/ShopWired/FormatKeyVals.php` | `FormatKeyVals` | Formats all POST data including filters |

### SQL Classes

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Sql/Marketing/SqlTableNewProducts.php` | `SqlTableNewProducts` | Contains `getFilterGroupQuery()` for local DB |
| `legacy/src/Sql/ShopWired/InsertInto.php` | `InsertInto` | Insert statements for local filter cache |

---

## 6. Key Code Paths

### Fetching Product with Filters

```php
// ProductFunct.php:48
public static function allEmbeds(bool $keyRequired = true): string
{
    $embed = trim(
        'images,brand,categories,related,extras,customization_fields,ebay_shipping_rates,options,product_option_values,vat_relief,digital_files,choices,filters,custom_fields,variations'
    );
    return ($keyRequired ? 'embed=' : '') . $embed;
}

// ProductEndpoint.php:70
public function getById(int $id, $embed = null, $fields = null): ?stdClass
{
    if (empty($embed)) {
        $embed = ProductFunct::allEmbeds(false);
    }
    // ...builds query string and fetches
}
```

### Hydrating Filters from API Response

```php
// Product.php:553
if (isset($data['filters']) && \is_object($data['filters'])) {
    $this->setFilters((get_object_vars($data['filters'])));
}

// Also at line 600 for non-object format:
if (!empty($data['filters'])) {
    $this->setFilters($data['filters']);
}
```

### Extracting Filters for API Request

```php
// Product.php:736
public function extract(): array
{
    $array = [];
    // ...other fields
    if ($this->getFilters() !== null) {
        $array['filters'] = $this->getFilters();
    }
    // ...
    return $array;
}
```

### Creating Product with Filters

```php
// CreateSwProductModel.php:141
private function createBasic(): void
{
    $create = $this->sw->post('products', $this->prodArray);
    // prodArray includes filters key
}
```

### Updating Product Filters

```php
// ShopWiredA.php:400
public function UpdateProduct(int $id, string $field, $values, ...): bool
{
    $data = [$field => $values];  // Can be ['filters' => [...]]
    // ...PUT request
}

// Or using raw data:
// ShopWiredA.php:973
public function productUpdateRawData(int $id, array $data, ...): bool
{
    // data can include 'filters' key
}
```

---

## 7. Database Schema (Local Cache)

### filter_groups

```sql
CREATE TABLE `filter_groups` (
  `id` int PRIMARY KEY,
  `title` varchar(255),
  `optionNo` int
);

-- Insert statement:
INSERT INTO `filter_groups` (`id`, `title`, `optionNo`)
VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE title = VALUES(title), optionNo = VALUES(optionNo)
```

### products_filters

```sql
CREATE TABLE `products_filters` (
  `fkId` int,          -- Product ID
  `optionNo` int,      -- Filter group optionNo
  `value` varchar(255) -- Filter value
);

-- Insert statement:
INSERT INTO `products_filters` (`fkId`, `optionNo`, `value`)
VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE optionNo = VALUES(optionNo), value = VALUES(value)
```

### category_filters

Links filter groups to categories (determines which filters show for products in specific categories):

```sql
CREATE TABLE `category_filters` (
  `fkFilterId` int,  -- filter_groups.id
  `fkCatId` int      -- category.id
);
```

---

## 8. Business Rules

### Filter Validation
- Filters are optional on products
- A product can have multiple values per filter group
- Empty filter arrays are allowed

### VAT Relief Filter
- `optionNo = 2` is specifically for "VAT Relief Eligible" filter
- Constant defined: `Filter::VAT_RELIEF_ELIGIBLE = 2`
- Special handling in UI to show/hide based on VAT settings

### Category-Filter Association
- Filters are associated with categories
- The UI shows/hides filter options based on selected product categories
- Query joins `category_filters`, `filter_groups`, and `products_filters`

---

## 9. Configuration

### Embed Parameter
The `filters` embed is included in the default embed string. No additional configuration needed.

### Form Field Naming Convention
- Form fields use `filters[optionNo][]` naming pattern
- Example: `<select name="1[]" multiple>` for filter group with optionNo=1
- Values are arrays (multi-select)

---

## 10. Known Issues / Technical Debt

1. **Undocumented API**: Filters are not in official ShopWired API docs - discovered through exploration
2. **Commented Code**: Filter.php has hydration methods that were partially implemented then commented out
3. **Dual Hydration**: Product.php has two separate filter hydration blocks (lines 553 and 600) handling different formats
4. **No Type Safety**: Filter values are stored as generic arrays without strict typing

---

## 11. Migration Notes

### For New Codebase Implementation

1. **Embed Parameter**: Add `filters` to your embed string when fetching products
2. **Data Type**: Handle filters as `Record<string, string[]>` (object with string keys and array values)
3. **API Format**: Filters use `optionNo` (integer) as the key, but JSON serializes it as string
4. **Nullable**: Filters property can be null if product has no filters assigned

### Example TypeScript Interface

```typescript
interface ProductFilters {
  [optionNo: string]: string[];
}

interface FilterGroup {
  id: number;
  title: string;
  optionNo: number;
}

interface Product {
  id: number;
  title: string;
  filters?: ProductFilters;
  // ...other fields
}
```

### API Request Example

```typescript
// Fetch product with filters
const response = await fetch(`/products/${id}?embed=filters`);

// Update filters
await fetch(`/products/${id}`, {
  method: 'PUT',
  body: JSON.stringify({
    filters: {
      "1": ["Small", "Medium"],
      "2": ["Yes"]
    }
  })
});
```

---

## 12. Testing Considerations

1. Test with products that have no filters (null/undefined)
2. Test with multiple values per filter group
3. Test filter groups endpoint separately
4. Verify category-filter relationships if implementing filter visibility logic