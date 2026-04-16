# Category & Brand — Frontend Field Usage Audit

**Date:** 2026-04-16
**Source:** alz-admin frontend codebase analysis
**Purpose:** Identify which fields from CategoryResource / BrandResource are actually consumed by frontend features, to inform backend schema/view review.

## Category — Fields Actually Used

| Field | Where | What For |
|-------|-------|----------|
| `id` | CategoryPicker, CategoryEditTabs, ProductViewGrid filter | Identity key everywhere |
| `title` | CategoryPicker, ProductViewPage filter dropdown | Display label in `<Select>` |
| `links.public_url` | CategoryEditPage -> EntityActionMenu | "View on website" link |
| `links.edit_website_url` | CategoryEditPage -> EntityActionMenu | "Edit in ShopWired" link |
| `description` | CategoryEditTabs -> CategoryDescriptionContent | Rich text editor (editable, via `?include=description`) |
| `description2` | CategoryEditTabs -> CategoryDescriptionContent | Rich text editor (read-only, via `?include=description2`) |
| `is_main_category` | ProductViewPage `toCategoryOptions` | Filters category picker to main categories only |

## Brand — Fields Actually Used

| Field | Where | What For |
|-------|-------|----------|
| `id` | BrandPicker, BrandEditTabs | Identity key |
| `title` | BrandPicker | Display label in `<Select>` |
| `links.public_url` | BrandEditPage -> EntityActionMenu | "View on website" link |
| `links.edit_website_url` | BrandEditPage -> EntityActionMenu | "Edit in ShopWired" link |
| `description` | BrandEditTabs -> BrandDescriptionContent | Rich text editor (editable, via `?include=description`) |

## Fields in Schema But NOT Used by Any Feature

These 8 fields exist on **both** Category and Brand, are validated by Zod on the frontend, but never read by any component or hook:

| Field | Notes |
|-------|-------|
| `slug` | Never accessed |
| `active` | Never accessed |
| `featured` | Never accessed |
| `sort_order` | Never accessed |
| `meta_title` | Never accessed |
| `meta_description` | Never accessed |
| `image_url` | Never accessed |
| `created_at` | Never accessed |

## Data Flow Summary

### List endpoints (`GET /categories`, `GET /brands`)

Return the full entity shape. Frontend only uses:
- **Category list:** `id`, `title`, `is_main_category`
- **Brand list:** `id`, `title`

Consumers: CategoryPicker, BrandPicker, ProductViewPage category filter dropdown.

### Detail endpoints (`GET /categories/{id}?include=...`, `GET /brands/{id}?include=...`)

Return full entity + included relations. Frontend only uses:
- **Category detail:** `id`, `links`, `description`, `description2`
- **Brand detail:** `id`, `links`, `description`

Consumers: CategoryEditPage/BrandEditPage -> EntityActionMenu + description editors.

### Custom fields (separate endpoints)

`GET/PUT /{entity}/{id}/custom-fields` — completely separate from the main entity shape. Not affected by changes to the category/brand resource.

### Mutations

- `PUT /categories/{id}` / `PUT /brands/{id}` — sends `ScalarFieldsUpdateRequest` (currently just `description` HTML)
- `PUT /categories/{id}/custom-fields` / `PUT /brands/{id}/custom-fields` — separate custom field updates

## Implications for Backend

1. **List view could be much leaner** — only `id`, `title`, and `is_main_category` (categories only) are needed.
2. **Detail view** needs `id`, `links`, `description`/`description2` — the other fields are returned but ignored.
3. If any of the 8 unused fields are removed from the Resource response, the frontend Zod schemas and TypeScript types in alz-admin will need a matching trim (trivial change).
4. `links` is a computed/virtual field (URLs constructed from slug) — even though `slug` itself isn't used directly, it may be needed server-side to generate the links.
