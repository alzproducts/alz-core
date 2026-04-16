# Contact Form Submission Improvements

## Context

The contact form submission system spans all Clean Architecture layers: Presentation DTOs validate input, Domain VOs model the data, Infrastructure persists to PostgreSQL and creates HelpScout conversations asynchronously. The current attribution schema only captures Google's `gclid` — missing other ad platforms. The HelpScout email body is sparse (just the customer message + optional product/metadata), making it hard for CS agents to get context at a glance.

**DB ↔ HelpScout linkage already exists** via `customer_service.contact_submission_actions.external_id` — no new reference IDs needed.

**product_id / sku already correct** — `productId` is required when product section present, `sku` is nullable throughout. No changes needed.

## Change 1: New Attribution Click ID Fields

Add `gclsrc`, `wbraid`, `gbraid`, `msclkid`, `fbclid` across all layers.

| Layer | File | Change |
|-------|------|--------|
| Presentation DTO | `app/Presentation/Http/ContactForm/DTOs/AttributionSectionRequestDTO.php` | Add 5 nullable string params with `#[Nullable, StringType, Max(255)]` |
| Domain VO | `app/Domain/ContactSubmission/ValueObjects/MarketingAttribution.php` | Add 5 `?string` constructor params (default null), update `hasAnyAttribution()` |
| Presentation Mapper | `app/Presentation/Http/ContactForm/Mappers/ContactSubmissionMapper.php` | Pass 5 new fields in `mapAttribution()` |
| Infrastructure Mapper | `app/Infrastructure/Ingest/ContactSubmission/Mappers/ContactSubmissionMapper.php` | Add to `toModelAttributes()` and `fromModel()` |
| Model docblock | `app/Infrastructure/Ingest/ContactSubmission/Models/ContactSubmissionModel.php` | Add 5 `@property string\|null` annotations |
| Migration | `database/migrations/2026_04_16_100000_add_click_id_columns_to_public_ingest_contact_submissions.php` | 5 nullable varchar(255) columns + partial indexes on `msclkid` and `fbclid` |

**Index decision**: `msclkid` (Microsoft Ads) and `fbclid` (Meta Ads) get partial B-tree indexes like `gclid` — primary click IDs for conversion tracking. `gclsrc`/`wbraid`/`gbraid` do NOT get indexes — `gclsrc` is a flag value, `wbraid`/`gbraid` are rarely populated Google privacy alternatives.

## Change 2: Restructure HelpScout Email Body

**File**: `app/Application/ContactSubmission/Transformers/ContactSubmissionToConversationCommandTransformer.php`

Current format (sparse plain text — just message + optional details):
```
{message}
---
{product if present}
{metadata if present}
```

New format (basic HTML with structured header):
```html
<strong>Name:</strong> John Smith<br>
<strong>Email:</strong> john@example.com<br>
<strong>Reason:</strong> Product Information<br>
<strong>Phone:</strong> 07123456789

<hr>

Hello, I need help with my order

<hr>

<strong>Product ID:</strong> 123 (SKU: ABC) - Title<br>
<strong>Price:</strong> £99.99<br>
<strong>Quantity:</strong> 3<br>
<strong>URL:</strong> https://example.com/product<br>

<strong>Customer Type:</strong> Care Home<br>
<strong>Order Number:</strong> ORD-999<br>
<strong>Delivery Postcode:</strong> AB1 2CD
```

HelpScout auto-detects HTML in the `text` field — no SDK changes needed.

Implementation:
- New `buildContactHeader()` method — formats Name/Email/Reason + optional Phone as HTML with `<strong>` labels and `<br>` line breaks
- Restructure `buildBody()` to assemble: header → `<hr>` → message → optional (`<hr>` → product+metadata)
- **Security: `htmlspecialchars()` all user-provided values** (name, email, message, etc.) before embedding in HTML to prevent XSS in the HelpScout agent UI
- Phone only included in header when present
- Product/metadata section only included when data exists
- Existing `formatProduct()` and `buildMetadata()` methods updated to output HTML (`<br>` instead of `\n`, `<strong>` labels)
- Update class docblock: distinguish "support-relevant" fields included in body (name, email, reason, phone) from "tracking" fields excluded (click IDs, UTMs, IP, user agent, page URL, referrer)
- Update `CreateCustomerConversationCommand` docblock — body is now HTML, not plain text

## Implementation Order

1. Database migration (purely additive, deploy-safe)
2. Domain VO — `MarketingAttribution` with 5 new fields + update `hasAnyAttribution()`
3. Presentation DTO + Mapper — accept new fields from frontend
4. Infrastructure Mapper + Model docblock — persist new fields
5. Transformer restructure — new email body format
6. Tests across all layers

## Test Files to Update

- `tests/Unit/Domain/ContactSubmission/ValueObjects/MarketingAttributionTest.php` — new field coverage + `hasAnyAttribution`
- `tests/Unit/Application/ContactSubmission/Transformers/ContactSubmissionToConversationCommandTransformerTest.php` — body format assertions, PII exclusion for new click IDs
- `tests/Integration/ContactSubmission/ContactFormEndToEndTest.php` — payload with new fields, DB persistence, body structure

## Verification

1. `make lint` — PHPStan, Pint, Arkitect, Deptrac pass
2. `make test` — all unit + integration tests pass
3. Manual: `curl` the contact form endpoint with new attribution fields, verify DB columns populated
4. Manual: inspect HelpScout conversation body matches new structured format
