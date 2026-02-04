# Reviews.io to ShopWired Product Reviews Sync - Handover Document

**Date:** 2026-02-05
**Feature:** Product Reviews Sync
**Source:** Reviews.io (reviews.co.uk)
**Destination:** ShopWired Custom Fields

---

## 1. Feature Overview

This feature synchronizes product review ratings from Reviews.io to ShopWired product custom fields. It runs as a daily cron job, fetching aggregated review data (average rating and total count) for each product's SKUs from Reviews.io, then updating corresponding ShopWired product custom fields. The system handles products with variations by aggregating reviews across all SKUs (master + variants) and calculating a weighted average rating.

---

## 2. Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              CRON TRIGGER                                        │
│                   html/cron/daily/review_prod_update_sw.php                      │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                    UpdateProductReviewCustomFields                               │
│         legacy/src/AlzMvc/Service/Api/Reviews/Updates/                           │
│                                                                                  │
│  • Fetches all ShopWired products with variations                                │
│  • Loops through products (max 2000 per run)                                     │
│  • Delegates to UpdateOneProductReviewFields for each product                    │
└─────────────────────────────────────────────────────────────────────────────────┘
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
┌───────────────────────────────────┐   ┌───────────────────────────────────────┐
│     GetAllWithVariations          │   │     UpdateOneProductReviewFields      │
│  (Fetches ShopWired Products)     │   │   (Updates single product)            │
│                                   │   │                                       │
│  Fields: id, sku, customFields    │   │  1. Get reviews via GetAvgReviewRating│
│  Includes: variations             │   │  2. Get count via GetProductTotalReviews│
│                                   │   │  3. Check if update needed            │
│                                   │   │  4. Call UpdateSwCf to update         │
└───────────────────────────────────┘   └───────────────────────────────────────┘
                                                        │
                    ┌───────────────────────────────────┴───────────────────┐
                    ▼                                                       ▼
┌───────────────────────────────────┐   ┌───────────────────────────────────────┐
│   GetAllReviewRatingsByProduct    │   │           UpdateSwCf                   │
│                                   │   │   (ShopWired Custom Field Update)      │
│  1. Get ShopWired product by ID   │   │                                       │
│  2. Extract all SKUs (+ variants) │   │  1. Fetch current customFields        │
│  3. Fetch ratings from Reviews.io │   │  2. Merge with new values             │
│     via GetProductReviewBatch     │   │  3. PUT to products/{id}              │
└───────────────────────────────────┘   └───────────────────────────────────────┘
                    │
                    ▼
┌───────────────────────────────────┐
│      Reviews.io API               │
│                                   │
│  GET product/rating-batch         │
│  ?store={store}&apikey={key}      │
│  &sku=SKU1;SKU2;SKU3              │
│                                   │
│  Returns per-SKU:                 │
│  - sku                            │
│  - average_rating                 │
│  - num_ratings                    │
└───────────────────────────────────┘
```

---

## 3. External Integrations

### 3.1 Reviews.io API

| Property | Value |
|----------|-------|
| **Base URL** | `https://api.reviews.co.uk/` |
| **Endpoint** | `product/rating-batch` |
| **Method** | GET |
| **Authentication** | Query parameters: `store`, `apikey` |

#### Request Format
```
GET https://api.reviews.co.uk/product/rating-batch?store={STORE_ID}&apikey={API_KEY}&sku=SKU1;SKU2;SKU3
```

- Multiple SKUs are joined with semicolons (`;`)
- Store ID and API key are automatically appended by Guzzle middleware

#### Response Format
```json
[
  {
    "sku": "SKU-001",
    "average_rating": "4.5",
    "num_ratings": 25
  },
  {
    "sku": "SKU-002",
    "average_rating": "4.2",
    "num_ratings": 12
  }
]
```

### 3.2 ShopWired API (for reference)

| Property | Value |
|----------|-------|
| **Endpoint** | `PUT /v1/products/{id}` |
| **Payload Field** | `customFields` (object) |
| **Authentication** | Existing ShopWired API client |

---

## 4. Code Inventory

### Entry Points

| File | Purpose |
|------|---------|
| `html/cron/daily/review_prod_update_sw.php` | Daily cron entry point. Calls `updateAllRatings(2000)` |

### Core Services

| Class | Location | Purpose |
|-------|----------|---------|
| `UpdateProductReviewCustomFields` | `legacy/src/AlzMvc/Service/Api/Reviews/Updates/` | Orchestrates the sync process, loops through products |
| `UpdateOneProductReviewFields` | `legacy/src/AlzMvc/Service/Shopwired/Product/Updates/` | Updates a single product's review custom fields |
| `UpdateSwCf` | `legacy/src/Api/AlzApi/UniqueProp/Update/` | Low-level ShopWired custom field update via API |

### Reviews.io Data Fetching

| Class | Location | Purpose |
|-------|----------|---------|
| `GetAllReviewRatingsByProduct` | `legacy/src/AlzMvc/Service/Api/Reviews/Products/` | Gets all ratings for a product (by all its SKUs) |
| `GetProductReviewBatch` | `legacy/src/AlzMvc/Service/Api/Reviews/Products/` | Fetches batch ratings from Reviews.io API |
| `GetAvgReviewRating` | `legacy/src/AlzMvc/Service/Api/Reviews/Products/` | Calculates weighted average rating |
| `GetProductTotalReviews` | `legacy/src/AlzMvc/Service/Api/Reviews/Products/` | Sums total review count |

### ShopWired Data Fetching

| Class | Location | Purpose |
|-------|----------|---------|
| `GetAllWithVariations` | `legacy/src/AlzMvc/Service/Shopwired/Product/Collection/` | Fetches all ShopWired products with variations |
| `GetAllSkus` | `legacy/src/AlzMvc/Service/Shopwired/Product/` | Extracts all SKUs from a product (master + variants) |
| `GetById` | `legacy/src/AlzMvc/Service/Shopwired/Product/` | Gets single product by ShopWired ID |

### Models

| Class | Location | Purpose |
|-------|----------|---------|
| `Rating` | `legacy/src/AlzMvc/Service/Api/Reviews/Models/` | DTO for Reviews.io rating response |

### API Configuration

| File | Purpose |
|------|---------|
| `legacy/src/AlzMvc/Core/Container/Guzzle/reviews_client.php` | Guzzle client configuration for Reviews.io |
| `legacy/src/AlzMvc/Core/Factory/Guzzle/Handler/ReviewsHandlerStack.php` | Auth middleware for Reviews.io requests |

---

## 5. Data Structures

### 5.1 ShopWired Custom Fields (Target)

| Field Name | Type | Description |
|------------|------|-------------|
| `average_rating` | `string` | Weighted average rating, rounded to 4 decimal places. E.g., `"4.2567"` |
| `num_ratings` | `string` | Total review count across all SKUs. E.g., `"42"` |

**Note:** Both fields are stored as strings in ShopWired custom fields, despite representing numeric values.

### 5.2 Reviews.io Rating Model

```php
final class Rating implements Hydratable, Extractable
{
    private string $sku;
    private string $average_rating;  // e.g., "4.5"
    private int $num_ratings;        // e.g., 25
}
```

### 5.3 Data Transformation

The `createReviewsData` method formats data for ShopWired:

```php
private static function createReviewsData(?int $numReviews, ?float $avgRating): array {
    return [
        'average_rating' => (string) ($avgRating ?? null),
        'num_ratings'    => (string) ($numReviews ?? ''),
    ];
}
```

---

## 6. Business Rules

### 6.1 SKU Aggregation

Reviews are fetched for **all SKUs** belonging to a product:
- Master product SKU
- All variation SKUs

```php
// From GetAllSkus::getSkusFromProductArr()
$skus[] = $product['sku'];  // Master SKU
foreach ($product['variations'] as $var) {
    if (!empty($var['sku'])) {
        $skus[] = $var['sku'];  // Variant SKUs
    }
}
```

### 6.2 Weighted Average Calculation

The average rating is calculated as a **weighted average** across all SKUs:

```php
// Formula: sum(rating × count) / total_count
$weightedSum = $collection->sum(fn($item) => $item->getAverageRating() * $item->getNumRatings());
$totalReviews = $collection->sum(fn($item) => $item->getNumRatings());
$averageRating = round($weightedSum / $totalReviews, 4);
```

**Example:**
- SKU-001: 4.5 stars, 10 reviews → contribution = 45
- SKU-002: 4.0 stars, 20 reviews → contribution = 80
- **Result:** (45 + 80) / 30 = 4.1667

### 6.3 Update Condition Check

Updates only occur when values differ from current ShopWired values:

```php
public static function doFieldsNeedUpdating(array $data, array $swProd): bool
{
    // Always update if customFields doesn't exist
    if (!isset($swProd['customFields']) && isset($swProd['id'])) {
        return true;
    }
    // Update if any key-value pair differs
    if (empty(ArrValidation::doAllKeyValuesMatchInArray($data, $swProd['customFields']))) {
        return true;
    }
    return false;
}
```

### 6.4 Empty/Zero Handling

- If no reviews exist, `average_rating` = `"0"` and `num_ratings` = `"0"`
- Empty results are not cached (to allow retry on next run)

### 6.5 Processing Limits

- Default batch size: **2000 products per cron run**
- Configurable via `updateAllRatings($numberToUpdate)` parameter

---

## 7. Configuration

### 7.1 Environment Variables

| Variable | Description |
|----------|-------------|
| `REVIEWS_API_KEY` | API key for Reviews.io authentication |
| `REVIEWS_STORE_ID` | Store identifier for Reviews.io |

### 7.2 Cache Configuration

| Cache Key | TTL | Purpose |
|-----------|-----|---------|
| `productAvgRating-{md5(id)}` | 1 hour | Caches calculated average rating |
| `productTotalReviews-{md5(id)}` | 1 hour | Caches total review count |
| `getAllProductReviewRatings-{md5(id)}` | 1 hour | Caches raw Reviews.io response |
| `product-review-batch-{md5(skus)}` | 1 hour | Caches API batch response |
| `getProductById-{id}` | Daily | Caches ShopWired product data |
| `getAllWithVariants-{fields}` | Daily | Caches all ShopWired products |

**Cache Implementation:** APCu via Symfony Cache component

### 7.3 Cron Schedule

- **Frequency:** Daily
- **Location:** `html/cron/daily/`
- **Default Limit:** 2000 products per execution

---

## 8. Known Issues & Technical Debt

### 8.1 String Type Coercion
Custom fields are stored as strings regardless of actual type. The new implementation should consider whether ShopWired supports typed custom fields.

### 8.2 No Error Recovery
Failed individual product updates are logged but don't halt the batch. No retry mechanism exists for failed updates.

### 8.3 Cache Invalidation
The `voidCache()` is called at the start of each run, but individual product caches persist. This could lead to stale data if products are modified between runs.

### 8.4 Commented Debug Code
Several files contain commented `var_dump` statements indicating debugging history.

### 8.5 Hardcoded Limits
The 2000 product limit is hardcoded in the cron file rather than being configurable via environment or config.

---

## 9. Migration Checklist

For implementing this feature in the new codebase:

- [ ] Create Reviews.io API client with authentication middleware
- [ ] Implement weighted average calculation for multi-SKU products
- [ ] Create ShopWired custom field update endpoint (if not exists)
- [ ] Implement caching strategy for API responses
- [ ] Set up scheduled job (daily recommended)
- [ ] Add logging for sync operations
- [ ] Handle edge cases: products with no reviews, single SKU vs multi-SKU

### Custom Field Names to Create in ShopWired (if not existing)

| Field Name | Display Name | Item Type |
|------------|--------------|-----------|
| `average_rating` | Average Rating | product |
| `num_ratings` | Number of Ratings | product |

---

## 10. API Reference Summary

### Reviews.io Batch Endpoint

```
GET https://api.reviews.co.uk/product/rating-batch
```

**Query Parameters:**
| Parameter | Required | Description |
|-----------|----------|-------------|
| `store` | Yes | Store ID (auto-added by middleware) |
| `apikey` | Yes | API key (auto-added by middleware) |
| `sku` | Yes | Semicolon-separated SKU list |

**Response:**
```json
[
  {
    "sku": "string",
    "average_rating": "string",  // e.g., "4.5"
    "num_ratings": integer       // e.g., 25
  }
]
```

---

*Document generated: 2026-02-05*
*Source codebase: alz-connect (legacy)*