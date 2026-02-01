# Contact Form Laravel Backend Handoff Document

## Executive Summary

This document specifies the data payload structure for migrating the AlzProducts contact form (`contactUsForm.js`) from ShopWired's native POST submission to a JavaScript-based submission to a Laravel backend endpoint.

**Current behavior:** Form submits via native HTML POST to ShopWired platform.
**Target behavior:** Form submits via JavaScript fetch/axios to Laravel `alz-core` backend.

---

## Complete Data Payload Schema

```typescript
interface ContactFormPayload {
  // === CORE FORM FIELDS ===
  form: {
    name: string;              // Required
    email: string;             // Required
    reason: ContactReason;     // Required - enum
    message: string;           // Required
    phone?: string;            // Optional
    customer_type?: CustomerType; // Conditional
    order_number?: string;     // Conditional
    delivery_postcode?: string; // Conditional
    quantity?: number;         // Conditional
  };

  // === SELECTED PRODUCT (if applicable) ===
  product?: {
    sku: string;
    title: string;
    price: string;
    url: string;
    manual_url?: string;
    source: 'recently_viewed' | 'recently_ordered';
  };

  // === SPAM PROTECTION ===
  spam: {
    honeypot_value: string;    // Should be empty for valid submissions
  };

  // === USER IDENTIFICATION ===
  user: {
    customer_id?: string;      // ShopWired customer ID (null/empty if guest)
  };

  // === CONSENT STATUS (Consent Mode v2) ===
  consent: {
    marketing: boolean;
    statistics: boolean;
    preferences: boolean;
    has_responded: boolean;    // true if user interacted with banner
  };

  // === MARKETING ATTRIBUTION ===
  attribution: {
    gclid?: string;
    utm_source?: string;
    utm_medium?: string;
    utm_campaign?: string;
    utm_content?: string;
    utm_term?: string;
  };

  // === PAGE/SESSION CONTEXT ===
  context: {
    page_url: string;
    referrer_url: string;
    user_agent: string;
    timestamp: string;         // ISO 8601 format
  };
}

// Enum: Contact Reasons
type ContactReason =
  | 'Product Information'
  | 'Checkout/Payment'
  | 'Quotation Request'
  | 'My Order - Delivery'
  | 'My Order - Returns'
  | 'My Order - Technical Support'
  | 'My Order - Other Query'
  | 'Marketing'
  | 'Other';

// Enum: Customer Types
type CustomerType =
  | 'personal'
  | 'nhs'
  | 'government'
  | 'care_home'
  | 'charity'
  | 'other_business'
  | 'prefer_not_to_say';
```

---

## Field Definitions

### A. Core Form Fields

| Field | Type | Required | Max Length | Validation | Notes |
|-------|------|----------|------------|------------|-------|
| `form.name` | string | Yes | - | Non-empty | Pre-filled for logged-in users |
| `form.email` | string | Yes | - | Valid email format | Pre-filled for logged-in users |
| `form.reason` | enum | Yes | - | Must match `ContactReason` enum | Triggers conditional field visibility |
| `form.message` | string | Yes | - | Non-empty | Textarea content |
| `form.phone` | string | No | - | - | Pre-filled for logged-in users (UK format, +44 stripped) |
| `form.customer_type` | enum | Conditional | - | Must match `CustomerType` enum | Shows when reason is NOT order-related |
| `form.order_number` | string | Conditional | 20 | Pattern: `A[0-9]+` | Shows when reason is order-related |
| `form.delivery_postcode` | string | Conditional | - | - | Shows only for "Quotation Request" |
| `form.quantity` | number | Conditional | - | 0-999 | Shows only for "Quotation Request" |

### B. Selected Product Fields

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `product.sku` | string | `<option data-sku>` | Product SKU |
| `product.title` | string | `<option data-title>` | Product title (without SKU prefix) |
| `product.price` | string | `<option data-price>` | Product price as string |
| `product.url` | string | `<option data-url>` | Product page URL |
| `product.manual_url` | string | `<option data-manual>` | Instruction manual URL (if available) |
| `product.source` | enum | Determined by field ID | `'recently_viewed'` for `#product`, `'recently_ordered'` for `#product-ordered` |

### C. Spam Protection

| Field | Type | Notes |
|-------|------|-------|
| `spam.honeypot_value` | string | Value from hidden `#website` field. **Valid submissions should have empty string.** |

### D. User Identification

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `user.customer_id` | string or null | `#top[data-user-id]` | ShopWired customer ID. Null/empty if guest. |

### E. Consent Status

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `consent.marketing` | boolean | `window.Cookiebot.consent.marketing` | Consent for Google Ads, marketing pixels |
| `consent.statistics` | boolean | `window.Cookiebot.consent.statistics` | Consent for GA4, Mixpanel, Sentry |
| `consent.preferences` | boolean | `window.Cookiebot.consent.preferences` | Consent for functional cookies |
| `consent.has_responded` | boolean | `window.Cookiebot.hasResponse` | `true` if user interacted with consent banner |

**Note:** If Cookiebot is not available, all consent values should default to `false`.

### F. Marketing Attribution

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `attribution.gclid` | string | URL param `?gclid=` | Google Ads click ID |
| `attribution.utm_source` | string | URL param `?utm_source=` | Traffic source |
| `attribution.utm_medium` | string | URL param `?utm_medium=` | Marketing medium |
| `attribution.utm_campaign` | string | URL param `?utm_campaign=` | Campaign name |
| `attribution.utm_content` | string | URL param `?utm_content=` | Ad content identifier |
| `attribution.utm_term` | string | URL param `?utm_term=` | Paid keyword |

**Note:** These are captured from URL query parameters. Frontend implementation will determine persistence strategy.

### G. Page/Session Context

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `context.page_url` | string | `window.location.href` | Full URL of contact page |
| `context.referrer_url` | string | `document.referrer` | Previous page URL |
| `context.user_agent` | string | `navigator.userAgent` | Browser user agent |
| `context.timestamp` | string | `new Date().toISOString()` | Client-side submission time |

---

## Conditional Field Logic

The `form.reason` selection determines which conditional fields are visible and should be sent:

| Reason | customer_type | order_number | delivery_postcode | product | quantity | product_ordered |
|--------|---------------|--------------|-------------------|---------|----------|-----------------|
| Product Information | Yes | No | No | Yes | No | No |
| Checkout/Payment | Yes | No | No | No | No | No |
| Quotation Request | Yes | No | Yes | Yes | Yes | No |
| My Order - Delivery | No | Yes | No | No | No | No |
| My Order - Returns | No | Yes | No | No | No | Yes* |
| My Order - Technical Support | No | Yes | No | No | No | Yes* |
| My Order - Other Query | No | Yes | No | No | No | Yes* |
| Marketing | Yes | No | No | No | No | No |
| Other | Yes | No | No | No | No | No |

*`product_ordered` only appears if user is logged in AND has order history

**Order-related reasons** (determined by `isOrderRelatedReason()` function):
- My Order - Delivery
- My Order - Returns
- My Order - Technical Support
- My Order - Other Query

---

## Example Payload

```json
{
  "form": {
    "name": "John Smith",
    "email": "john.smith@example.com",
    "reason": "Quotation Request",
    "message": "I need a quote for 50 units of the mobility scooter for our care home.",
    "phone": "07700900123",
    "customer_type": "care_home",
    "delivery_postcode": "SW1A 1AA",
    "quantity": 50
  },
  "product": {
    "sku": "MOB-SCOOT-001",
    "title": "Mobility Scooter - Standard Model",
    "price": "899.99",
    "url": "https://www.alzproducts.co.uk/mobility-scooter-standard",
    "manual_url": "https://www.alzproducts.co.uk/manuals/mob-scoot-001.pdf",
    "source": "recently_viewed"
  },
  "spam": {
    "honeypot_value": ""
  },
  "user": {
    "customer_id": "12345"
  },
  "consent": {
    "marketing": true,
    "statistics": true,
    "preferences": false,
    "has_responded": true
  },
  "attribution": {
    "gclid": "CjwKCAjw-abc123",
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "care-homes-q1",
    "utm_content": null,
    "utm_term": "mobility aids bulk"
  },
  "context": {
    "page_url": "https://www.alzproducts.co.uk/contact-us",
    "referrer_url": "https://www.alzproducts.co.uk/mobility-scooters",
    "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "timestamp": "2026-01-30T14:32:15.123Z"
  }
}
```

---

## File Reference Table (shopwired-theme project)

### Primary Files

| File | Purpose | Key Exports/Elements |
|------|---------|---------------------|
| `assets/js/entry/contact/contactUsForm.js` | Main form handler | `contactUsForm()`, `pushContactFormDataToDataLayer()` |
| `src/views/contact.twig` | Contact page template | Page structure |
| `src/views/partials/contact_us_form.twig` | Form HTML template | All form fields, data attributes |

### Constants & Validation

| File | Purpose | Key Exports |
|------|---------|-------------|
| `assets/js/utils/contactFormConstants.js` | Field constants | `CONTACT_REASONS`, `CUSTOMER_TYPES`, `FIELD_LIMITS`, `VALIDATION_PATTERNS` |
| `assets/js/utils/urlQueryParams.js` | URL param handling | `parseQueryParams()`, `validateContactFormParams()` |
| `assets/js/utils/formSanitization.js` | Input sanitization | `sanitizeFormValue()` |

### Consent & Analytics

| File | Purpose | Key Exports |
|------|---------|-------------|
| `assets/js/utils/cookiebotConsent.js` | Consent API | `hasMarketingConsent()`, `hasStatisticsConsent()`, `hasPreferencesConsent()`, `isCookiebotAvailable()` |
| `src/assets/vendor/consentDefaults.js` | Consent Mode v2 defaults | gtag consent configuration |
| `assets/js/events/ga4/generateLead.js` | Lead event logic | `generateLead()`, `isOrderRelatedReason()` |

### User Data

| File | Purpose | Key Exports |
|------|---------|-------------|
| `src/views/partials/user_data_attributes.twig` | User data in DOM | `data-user-id`, `data-user-email`, etc. |
| `assets/js/analytics/gaUserTracking.js` | User data extraction | `getEnhancedConversionsData()`, `addUserIdTracking()` |
| `assets/js/entry/global/domDataUtils.js` | DOM data helpers | `getUserDataElement()` |

### Data Normalization

| File | Purpose | Key Exports |
|------|---------|-------------|
| `assets/js/utils/dataNormalizers.js` | Data normalization | `normalizeEmail()`, `normalizePhoneNumber()`, `normalizeAddress()` |
| `assets/js/utils/enhancedConversions.js` | Enhanced conversions | `createEnhancedConversionsData()`, `mergeUserDataWithFormInputs()` |

---

## Notes for Backend Team

### 1. Spam Validation
The `spam.honeypot_value` field should be validated server-side. **Reject any submission where this field is non-empty.** Do not return an error to the client (silent rejection prevents bot feedback).

### 2. Consent Handling
The consent values are provided for:
- **Audit trail**: Knowing what consent was granted at submission time
- **Marketing compliance**: Backend can decide whether to add user to marketing lists based on `consent.marketing`
- **Analytics correlation**: Can filter/segment submissions by consent status

### 3. Customer ID Correlation
The `user.customer_id` is the ShopWired platform customer ID. If you need to correlate with existing Laravel user records, you'll need a mapping table.

### 4. Order Number Format
Order numbers follow the pattern `A[0-9]+` (e.g., `A123456`). The form sends the value as entered by the user.

### 5. Timestamp
The `context.timestamp` is client-side time. Server should also record server-side receipt time for accuracy.

### 6. No reCAPTCHA
There is currently no reCAPTCHA on the contact form. Honeypot is the primary spam protection. Consider adding server-side rate limiting.

---

## Expected HTTP Response Codes

The frontend expects standard HTTP status codes (RESTful convention):

| Status | Meaning | Frontend Behavior |
|--------|---------|-------------------|
| `200` / `201` | Success | Show success message, reset form |
| `400` | Bad Request (validation errors) | Show validation errors |
| `422` | Unprocessable Entity | Show validation errors |
| `429` | Too Many Requests | Show rate limit message |
| `500` | Server Error | Show generic error, log to Sentry |

**Note:** Honeypot failures should return `200` (silent rejection) to avoid tipping off bots.

---

## Open Questions

1. **Email notifications**: Should the Laravel backend send email notifications, or will that remain with ShopWired?
2. **Rate limiting**: Should we implement submission rate limiting on the frontend, backend, or both?
3. **File attachments**: The current form has no file upload. Is this a future requirement?