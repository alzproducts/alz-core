# Contact Form API - Frontend Handoff Document

API contract for the AlzProducts contact form integration.

## Endpoint

```
POST /api/contact
Content-Type: application/json
```

- **No authentication** required
- **CORS enabled** for configured origins (set via `CORS_ALLOWED_ORIGINS` env)
- **Rate limited**: 5 requests/minute per IP

---

## Request Payload

```jsonc
{
  "form": { /* required */ },
  "consent": { /* required */ },
  "context": { /* required */ },
  "spam": { /* required */ },
  "attribution": { /* optional - omit if empty */ },
  "product": { /* optional - omit if none selected */ },
  "user": { /* optional - omit if anonymous */ }
}
```

### `form` (required)

Core form fields from user input.

| Field | Type | Required | Constraints | Notes |
|-------|------|----------|-------------|-------|
| `name` | string | ✅ | max 255 | Customer's full name |
| `email` | string | ✅ | max 255 | Contact email address |
| `reason` | string | ✅ | enum (see below) | **Uses label format** |
| `message` | string | ✅ | No max limit | Free-text message body |
| `phone` | string | ❌ | max 50 | Contact phone number |
| `customer_type` | string | ❌ | enum (see below) | **Uses value format** |
| `order_number` | string | ❌ | max 20 | For order-related queries |
| `delivery_postcode` | string | ❌ | max 20 | For delivery queries |
| `quantity` | integer | ❌ | 1-999 | For quotation requests |

#### `form.reason` enum values

**Send the human-readable label** (not the internal value):

```json
"Product Information"
"Checkout/Payment"
"Quotation Request"
"My Order - Delivery"
"My Order - Returns"
"My Order - Technical Support"
"My Order - Other Query"
"Marketing"
"Other"
```

Order-related reasons (`My Order - *`) should show the order number field.

#### `form.customer_type` enum values

**Send the internal value** (not the label):

```json
"personal"
"nhs"
"government"
"care_home"
"charity"
"other_business"
"prefer_not_to_say"
```

Note: `prefer_not_to_say` is a frontend-only value that maps to `null` in the backend.

---

### `consent` (required)

Cookie/marketing consent status captured at submission time.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `marketing` | boolean | ✅ | Marketing communications consent |
| `statistics` | boolean | ✅ | Analytics tracking consent |
| `preferences` | boolean | ✅ | Functionality preferences consent |
| `has_responded` | boolean | ✅ | Whether user interacted with consent banner |

All four fields are required booleans. Accepts `true`/`false` or `"true"`/`"false"` strings.

---

### `context` (required)

Page and session metadata captured at submission time.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `timestamp` | string | ✅ | ISO 8601 datetime (e.g., `2025-02-04T14:30:00Z`) |
| `page_url` | string | ❌ | Current page URL at submission |
| `referrer_url` | string | ❌ | HTTP referrer URL |
| `user_agent` | string | ❌ | Browser user agent string |

Timestamp is required — omitting it returns a 422 error. If the value is present but malformed, the server falls back to server time.

---

### `spam` (required)

Honeypot field for spam detection.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `honeypot_value` | string | ✅ | **Must be empty string** |

```json
"spam": {
  "honeypot_value": ""
}
```

**Implementation**: Create a hidden form field (CSS `display: none` or `position: absolute; left: -9999px`). Bots auto-fill hidden fields; humans don't see them. If filled, the request is silently rejected with a fake success response.

---

### `attribution` (optional)

Marketing attribution for conversion tracking. **Omit the entire section if no values are present.**

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `gclid` | string | max 255 | Google Click ID |
| `utm_source` | string | max 255 | Campaign source |
| `utm_medium` | string | max 255 | Campaign medium |
| `utm_campaign` | string | max 255 | Campaign name |
| `utm_content` | string | max 255 | Ad content identifier |
| `utm_term` | string | max 255 | Search keywords |

All fields are nullable. Read from URL query parameters or stored cookies.

---

### `product` (optional)

Selected product context. **Omit the entire section if no product is selected.**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `sku` | string | ✅ | Product SKU (required if section present) |
| `title` | string | ❌ | Product title |
| `price` | string | ❌ | Formatted price (e.g., "£49.99") |
| `url` | string | ❌ | Product page URL |
| `manual_url` | string | ❌ | User-entered product URL |
| `source` | string | ❌ | enum: `recently_viewed` or `recently_ordered` |

#### `product.source` enum values

```json
"recently_viewed"
"recently_ordered"
```

---

### `user` (optional)

Logged-in customer identification. **Omit the entire section if user is anonymous.**

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| `customer_id` | string | max 50 | ShopWired customer ID |

---

## Response Formats

### Success (200 OK)

```json
{
  "id": "01234567-89ab-cdef-0123-456789abcdef"
}
```

The `id` is a UUID representing the stored submission. Display a success message and optionally store this ID for reference.

### Validation Error (422 Unprocessable Entity)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "form.email": ["The form.email field is required."],
    "form.reason": ["The selected form.reason is invalid."]
  }
}
```

Errors are keyed by the dot-notation field path. Map these to form fields for inline validation display.

### Rate Limited (429 Too Many Requests)

```json
{
  "message": "Too many requests"
}
```

Response includes a `Retry-After` header with seconds until the next allowed request.

### Server Error (500 Internal Server Error)

```json
{
  "message": "Server Error"
}
```

Unexpected error. Suggest user retry later.

---

## UX Error Handling

| Response | Frontend Action |
|----------|-----------------|
| **200** + UUID | Show success confirmation, clear form |
| **422** | Map `errors` object to form fields, display inline validation messages |
| **429** | Show "Too many attempts. Please wait and try again." Optionally show countdown using `Retry-After` header |
| **500** | Show "Something went wrong. Please try again later." |

### Honeypot Behavior

Spam-filtered submissions return **200 OK** with a fake UUID (`spam-filtered`). This is intentional — bots cannot distinguish success from filtered.

**Frontend treats all 200 responses identically.** Do not check the ID value; simply show the success message.

---

## Example Request

```json
{
  "form": {
    "name": "Jane Smith",
    "email": "jane.smith@example.com",
    "reason": "Product Information",
    "message": "I'd like to know if this item is suitable for outdoor use.",
    "phone": "07700 900123",
    "customer_type": "personal"
  },
  "consent": {
    "marketing": false,
    "statistics": true,
    "preferences": true,
    "has_responded": true
  },
  "context": {
    "timestamp": "2025-02-04T14:30:00.000Z",
    "page_url": "https://alzproducts.co.uk/contact",
    "referrer_url": "https://alzproducts.co.uk/products/outdoor-chair",
    "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
  },
  "spam": {
    "honeypot_value": ""
  },
  "attribution": {
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "spring_sale_2025",
    "gclid": "Cj0KCQiA..."
  },
  "product": {
    "sku": "ALZ-CHAIR-001",
    "title": "Ergonomic Outdoor Chair",
    "price": "£149.99",
    "url": "https://alzproducts.co.uk/products/outdoor-chair",
    "source": "recently_viewed"
  }
}
```

---

## Validation Quick Reference

| Field | Validation |
|-------|------------|
| `form.name` | Required, string, max 255 |
| `form.email` | Required, string, max 255 |
| `form.reason` | Required, must be one of 9 label values |
| `form.message` | Required, string, no max limit |
| `form.phone` | Optional, string, max 50 |
| `form.customer_type` | Optional, must be one of 7 values |
| `form.order_number` | Optional, string, max 20 |
| `form.delivery_postcode` | Optional, string, max 20 |
| `form.quantity` | Optional, integer 1-999 |
| `consent.*` | Required, boolean |
| `context.timestamp` | Required, ISO 8601 datetime (malformed values fall back to server time) |
| `context.*` (others) | Optional, string |
| `spam.honeypot_value` | Required, must be empty string |
| `attribution.*` | Optional, string, max 255 |
| `product.sku` | Required if `product` section present |
| `product.source` | Optional, `recently_viewed` or `recently_ordered` |
| `user.customer_id` | Optional, string, max 50 |

---

## CORS Configuration

The API accepts cross-origin requests from configured domains only. Preflight (`OPTIONS`) responses are cached for 24 hours.

**Allowed Headers**: `Content-Type`, `Accept`, `X-Requested-With`

If you receive CORS errors, verify the origin domain is configured in the backend's `CORS_ALLOWED_ORIGINS` environment variable.
