# TinyMCE Setup — Legacy Feature Handover

**Date:** 2026-03-29
**Feature:** Rich text editing across the AlzConnect admin panel
**Primary file:** `legacy/src/Alz/TinyMCE.php`

---

## 1. Feature Overview

TinyMCE provides rich text editing for two main business functions: **composing HelpScout emails** and **editing product descriptions**. It uses TinyMCE 5.x loaded via Tiny Cloud CDN with a **premium subscription** (premium plugins are in use). Three generations of PHP wrapper exist, each used in different parts of the application.

> **Note:** A separate, older WYSIWYG editor — **LineControl 1.1.0** (by Suyati Technologies) — is also loaded globally via `editor.js` and `editor.css` in `header.html.twig`. The `<!-- LATEST WYSIWYG EDITOR -->` comment on line 31 refers to LineControl, not TinyMCE. The `richtext.min.css` include (line 21) is also LineControl-related. During migration, determine whether LineControl is still actively used or can be dropped entirely.

---

## 2. Architecture Diagram

```
header.html.twig
  └── Loads TinyMCE 5 via CDN (Tiny Cloud, premium key)

Usage Context 1: Email Composition
  Form field type "tinymce"
    ├── Form\Leg\Field::tinyMceFactory() → creates field config
    ├── Form\Leg\Field::createField() → detects type "tinymce"
    ├── NewProduct\Form\FieldGroup\CustomFieldGroupNp → detects type "tinymce"
    ├── Form\OneForm\HsEmailForm\AbstractHsEmailForm → detects type "tinymce"
    └── All delegate to → Alz\TinyMCE::CreateAreaEmail()
                            └── Uses TinyMceSettings trait (STD plugins/toolbar)
                            └── Renders inline <script> with tinymce.init()

Usage Context 2: Product Descriptions (New Product Form)
  NewProduct\Form\Field\Description\Description1  (editable)
  NewProduct\Form\Field\Description\CopyDesc       (read-only comparison)
    └── Both extend AbstractProductDesc
          └── Extends Form\Field\TinyM\AbstractTiny
                └── Renders inline <script> with tinymce.init()

Usage Context 3: HelpScout Email (newer MVC path)
  Mvc\Service\Form\HelpScout\Field\FieldEmailContent
    └── Creates Mvc\Service\Form\TinyMce\TinyMceArea (factoryBasic)
          └── Uses TinyMceSettings trait (STD plugins/toolbar)
```

---

## 3. Installation & Version

| Detail | Value |
|--------|-------|
| **Version** | TinyMCE 5.x (loaded as `tinymce/5/tinymce.min.js`) |
| **Delivery** | Tiny Cloud CDN (`cdn.tiny.cloud`) |
| **Subscription** | Premium (uses premium-only plugins) |
| **API Key** | Hardcoded in `templates/header.html.twig:52`. Not env-configured — should be moved to an environment variable for the new site. (Note: `TINY_KEY` in `.env` is for Tinify image compression, not TinyMCE.) |
| **Loaded in** | `templates/header.html.twig` (line 52) — loaded globally on every page |
| **No local files** | Not installed via npm/composer; purely CDN |
| **Also loaded** | LineControl 1.1.0 (`editor.js`, `editor.css`, `richtext.min.css`) — separate WYSIWYG, unrelated to TinyMCE |

---

## 4. Code Inventory

### Implementation Classes

| File | Role | Used By |
|------|------|---------|
| `legacy/src/Alz/TinyMCE.php` | Static helper (oldest). `CreateAreaEmail()` entry point | Legacy forms, HelpScout email forms, custom field groups |
| `legacy/src/Mvc/Service/Form/TinyMce/TinyMceSettings.php` | Trait: plugin & toolbar string definitions (STD, FULL) | `TinyMCE.php`, `TinyMceArea` |
| `legacy/src/Mvc/Service/Form/TinyMce/TinyMceFactory.php` | Trait: `factoryBasic()` static constructor | `TinyMceArea` |
| `legacy/src/Mvc/Service/Form/TinyMce/TinyMceArea.php` | OOP wrapper with fluent setters. Implements `Html` interface | HelpScout email field (newer path) |
| `legacy/src/Form/Field/TinyM/AbstractTiny.php` | Abstract builder (newest). Most configurable | Product descriptions |
| `legacy/src/Form/Field/TinyM/Template.php` | Value object for insertable content templates | `AbstractTiny` subclasses |
| `legacy/src/NewProduct/Form/Field/Description/AbstractProductDesc.php` | Product description config: custom plugins, toolbar, height | Product form |
| `legacy/src/NewProduct/Form/Field/Description/Description1.php` | Concrete: editable product description | New product page |
| `legacy/src/NewProduct/Form/Field/Description/CopyDesc.php` | Concrete: read-only copy description (side-by-side comparison) | New product page |

### Consumer Files

| File | How it uses TinyMCE |
|------|---------------------|
| `legacy/src/Form/Leg/Field.php` | Detects `type: 'tinymce'` → calls `TinyMCE::CreateAreaEmail()` |
| `legacy/src/Form/Leg/FieldFactory.php` | `tinyMceFactory()` creates field config with `type: 'tinymce'` |
| `legacy/src/NewProduct/Form/FieldGroup/CustomFieldGroupNp.php` | Detects `type: 'tinymce'` → calls `TinyMCE::CreateAreaEmail()` |
| `legacy/src/Form/OneForm/HsEmailForm/AbstractHsEmailForm.php` | Detects `type: 'tinymce'` → calls `TinyMCE::CreateAreaEmail()` |
| `legacy/src/Mvc/Service/Form/HelpScout/Field/FieldEmailContent.php` | Uses `TinyMceArea::factoryBasic()` |
| `legacy/src/Mvc/Service/Form/HelpScout/Field/HsFieldFactory.php` | `asTinyArea()` method creates `TinyMceArea` |
| `html/js/page/new_product.js` | JS: `tinymce.get("description").setContent(...)` for copy-description feature |

---

## 5. Business Rules & Settings

### 5.1 Plugin Tiers

Three plugin presets exist. All are defined as string constants/methods:

**PLUGINS_MIN** (product descriptions actually use a custom set):
```
powerpaste searchreplace link tinymcespellchecker a11ychecker linkchecker autoresize lists
```

**PLUGINS_STD** (emails and general forms — the default):
```
print preview fullpage powerpaste searchreplace autolink directionality advcode
visualblocks visualchars fullscreen image link media mediaembed template codesample
table charmap hr pagebreak nonbreaking anchor toc insertdatetime advlist lists
wordcount tinymcespellchecker a11ychecker imagetools textpattern help formatpainter
permanentpen pageembed tinycomments mentions linkchecker checklist autoresize
```

**PLUGINS_FULL** (identical to STD in current code — defined separately for future divergence):
Same as STD.

**Product Description plugins** (overridden in `AbstractProductDesc`):
```
powerpaste searchreplace tinymcespellchecker a11ychecker linkchecker autoresize lists
casechange fullscreen export print advcode template quickbars autosave
```

### 5.2 Premium Plugins in Use

These require a Tiny Cloud premium subscription:

| Plugin | Purpose |
|--------|---------|
| `powerpaste` | Clean paste from Word/Google Docs |
| `advcode` | Advanced code editor view |
| `tinymcespellchecker` | Pro spellchecker (not browser-native) |
| `a11ychecker` | Accessibility checking |
| `formatpainter` | Copy formatting between text |
| `permanentpen` | Persistent formatting tool |
| `linkchecker` | Validates links |
| `tinycomments` | Inline commenting/annotations |
| `mentions` | @mentions (configured but appears unused — mentions_fetch is commented out) |
| `mediaembed` | Enhanced media embedding |
| `pageembed` | Embed external pages |
| `casechange` | Change text case (product descriptions only) |
| `quickbars` | Floating toolbars on selection (product descriptions only) |
| `export` | Export content (product descriptions only) |

### 5.3 Toolbar Configurations

**TOOLBAR_MIN** (defined but not actively used via code path):
```
undo redo | formatselect | bold italic | link | alignleft | numlist bullist | removeformat | addcomment | pastetext | searchreplace
```

**TOOLBAR_STD** (emails and general forms):
```
formatselect | bold italic strikethrough | link image | alignleft alignright | numlist bullist | removeformat | addcomment | code | pastetext | checklist | print | searchreplace | template
```

**TOOLBAR_FULL** (available but not actively assigned by default):
```
formatselect | bold italic strikethrough forecolor backcolor permanentpen formatpainter | link image media pageembed | alignleft aligncenter alignright alignjustify | numlist bullist outdent indent | removeformat | addcomment | code | pastetext | checklist | print | searchreplace | template
```

**Product Description toolbar** (custom — defined in `AbstractProductDesc`):
```
undo redo | formatselect | bold italic | link | numlist bullist | removeformat | spellcheckdialog | searchreplace | pastetext | code | casechange | fullscreen | alignleft | template | spellchecker
```

### 5.4 Editor Settings by Context

#### Email Composition (STD)

| Setting | Value | Business Reason |
|---------|-------|-----------------|
| `menubar` | `true` | Full menu access for email authoring |
| `selector` | `textarea.tinyMCE` | Targets all textareas with class `tinyMCE` |
| `spellchecker_language` | `en_uk` | UK English (business is UK-based) |
| `spellchecker_dialog` | `true` | Opens spellcheck dialog — suits review-then-send email workflow |
| `image_caption` | `true` | Allows captioned images |
| `tinycomments_mode` | `embedded` | Comments stored within content |
| `autoresize_bottom_margin` | `20` | Padding below content |
| `template_cdate_format` | `[CDATE: %m/%d/%Y : %H:%M:%S]` | Creation date stamp in templates |
| `template_mdate_format` | `[MDATE: %m/%d/%Y : %H:%M:%S]` | Modified date stamp in templates |
| Default height | 30 rows (~textarea rows) | Generous space for email composition |
| Templates | Loaded from data (database email templates) | Allows inserting pre-built email templates |

#### Product Descriptions

| Setting | Value | Business Reason |
|---------|-------|-----------------|
| `height` | `600` (px) | Large editing area for product descriptions |
| `menubar` | `'file edit view tools'` | Restricted: no Insert/Format/Table menus |
| `selector` | `#description` or `#descriptionExp` | Targets specific fields by ID |
| `readonly` | `true` for CopyDesc | Side-by-side comparison of copy text vs editable desc |
| `resize` | `true` | User can manually resize |
| `spellchecker_dialog` | `false` | Uses inline/as-you-type spellcheck — suits long-form editing workflow |
| `fontsize_formats` | `''` (empty) | **Font sizes disabled** — enforces consistent sizing across product pages |
| `block_formats` | `Paragraph=p; Header 3=h3; Header 4=h4` | **No H1/H2 allowed** — product pages reserve H1/H2 for page title and product name |
| `quickbars_insert_toolbar` | `false` | No floating insert toolbar |
| `quickbars_image_toolbar` | `false` | No floating image toolbar |
| `quickbars_selection_toolbar` | `bold italic \| quicklink h3 h4 \| formatselect` | Quick formatting on text selection |
| `autosave_retention` | `1440m` (24 hours) | Prevents loss of long editing sessions |
| `style_formats` | Custom (see below) | Restricts available styles |
| Templates | `[{name: 'Product Title', content: 'Example Product'}]` | Single starter template |

**Custom Style Formats (Product Descriptions):**
```json
[
  { "title": "Headings", "items": [
    { "title": "Heading 3", "format": "h3" },
    { "title": "Heading 4", "format": "h4" }
  ]},
  { "title": "Inline", "items": [
    "Bold", "Italic", "Underline", "Strikethrough",
    "Superscript", "Subscript", "Code"
  ]},
  { "title": "Blocks", "items": [
    "Paragraph", "Blockquote", "Div", "Pre"
  ]},
  { "title": "Align", "items": [
    "Left", "Center", "Right", "Justify"
  ]}
]
```

#### HelpScout Email (newer MVC path via TinyMceArea)

Same settings as Email Composition (STD), but:
- Uses `selector: '#TinyMCE'` (by element ID, not class)
- `min_height` and `height` set via constructor (default 200px)
- Supports `readonly` property
- `resize: true`

### 5.5 Annotation/Comment Styling

All implementations use the same embedded comment styling:
```css
.mce-annotation { background: #fff0b7; }
.tc-active-annotation { background: #ffe168; color: black; }
```
This provides yellow highlight for annotations, with a darker yellow for the active one.

### 5.6 Template System

The TinyMCE template feature is used in two ways:

1. **Email templates**: Loaded from database data (`$data['value']`). Each template has a `tempName` and `tempContent` field. Falls back to "Template N" naming and "Template Content does not exist" placeholder.

2. **Product templates**: Hardcoded single template: `{name: 'Product Title', content: 'Example Product'}`.

All templates use the same JS structure:
```js
{ title: "Name", description: "Name", content: "HTML content" }
```

### 5.7 JS-Side Integration

`html/js/page/new_product.js` line 87 uses `tinymce.get("description").setContent(...)` to programmatically set the description content when copying from an existing product. This is the "copy description" feature on the new product form.

---

## 6. Configuration Summary

| Config Item | Location | Notes |
|-------------|----------|-------|
| CDN URL + API Key | `templates/header.html.twig:52` | Loaded globally. API key is hardcoded (not env-configured). |
| Plugin presets | `TinyMceSettings.php` (STD/FULL), `AbstractTiny.php` (MIN/STD/FULL), `AbstractProductDesc.php` (product-specific) | Three sources of truth |
| Toolbar presets | Same files as plugins | Three sources of truth |
| Style/block formats | `AbstractTiny.php`, `AbstractProductDesc.php` | Product descriptions restrict H1/H2 |
| Spellchecker language | Hardcoded `en_uk` in all implementations | UK English |
| Autosave retention | `AbstractTiny.php` only | 24 hours, product descriptions only |

---

## 7. Known Issues & Technical Debt

1. **Three parallel implementations** — `Alz\TinyMCE`, `TinyMceArea`, and `AbstractTiny` all serve the same purpose with slight variations. The plugin/toolbar strings are duplicated across `TinyMceSettings` trait and `AbstractTiny` constants.

2. **PLUGINS_STD and PLUGINS_FULL are identical** in `TinyMceSettings` — the "full" tier adds no extra plugins, only a different toolbar.

3. **Mentions plugin loaded but unused** — `mentions` is included in STD/FULL plugin lists, and there's a commented-out `mentionsFetchFunction` in `TinyMCE.php`. The feature was never completed.

4. **`tinycomments_mode: 'embedded'`** — Comments are embedded in the HTML content itself (no external storage). This means comments persist in saved content but there's no notification or collaboration layer.

5. **Template date format uses US date order** (`%m/%d/%Y`) despite being a UK business — possible oversight.

6. **`<label for"...">` missing equals sign** in `TinyMceArea.php:193` and `AbstractTiny.php:384` — `for"name"` instead of `for="name"`. Minor HTML validity issue.

7. **Product description `Description1`** passes `null` as description content (always starts empty) — the copy feature in JS populates it client-side.

8. **TinyMCE 5 end-of-life** — TinyMCE 5 has been superseded by TinyMCE 6/7. The premium subscription key may need renewal/migration.

9. **API key hardcoded** — The TinyMCE Cloud API key is hardcoded in `header.html.twig` rather than loaded from an environment variable. (Note: the existing `TINY_KEY` env var is for Tinify image compression, not TinyMCE.)

10. **LineControl also globally loaded** — `editor.js`, `editor.css`, and `richtext.min.css` load LineControl 1.1.0 on every page. The misleading comment `<!-- LATEST WYSIWYG EDITOR -->` in `header.html.twig:30` refers to LineControl, not TinyMCE. Likely dead weight — candidate for removal during migration.

---

## 8. Migration Recommendations

When reimplementing on the new website:

1. **Preserve the heading restriction for product descriptions** — H3/H4 only (no H1/H2) is a deliberate SEO/layout decision.
2. **Preserve UK English spellcheck** (`en_uk`).
3. **Preserve font-size disabling** for product descriptions to ensure consistent product page presentation.
4. **Evaluate premium plugins** — decide which are genuinely needed. Key ones: `powerpaste` (clean paste from Word), `tinymcespellchecker` (UK English), `a11ychecker`. Others like `mentions`, `permanentpen`, `formatpainter` appear underutilised.
5. **Fix the template date format** to use UK date order if still relevant.
6. **Consolidate to a single implementation** with configuration options rather than three parallel class hierarchies.
7. **Consider whether `tinycomments` is needed** — if comments aren't being used in a collaborative workflow, it adds complexity for no benefit.
8. **Autosave (24h)** on product descriptions is valuable — preserve this in migration.
9. **Spellcheck workflow difference** — preserve dialog-based for emails (review-then-send) and inline for product descriptions (long-form editing).
10. **Remove LineControl** — audit whether any pages still use it; if not, drop `editor.js`, `editor.css`, and `richtext.min.css` from the global header.
11. **Move API key to environment variable** — the TinyMCE Cloud API key should be loaded from env config, not hardcoded.
