# Sign Variant Gap Analysis

**Date:** 2026-02-06
**Data source:** `shopwired.products` + `shopwired.product_variations` (synced 2026-02-05)
**Query method:** JSONB options column (`Colour`, `Size`, `Type`)

## Identification Criteria

Signs were identified as products with **all three** variant options: `Colour`, `Size`, and `Type`. This returned **76 products**.

## Two Product Lines

Signs fall into two distinct product lines based on the `Type` option values:

### Standard Line (49 products) — 9 size/type combos per colour

| Size | Types |
|------|-------|
| 300mm | Hygenus, Standard, Self-Adhesive, Budget Fixed, Budget Self-Adhesive |
| 400mm | Hygenus, Standard |
| 480mm | Hygenus, Standard |

**Expected variants:** 9 combos x 4 colours (Blue, Green, Red, Yellow) = **36**

### Fixed Line (22 products) — 8 size/type combos per colour

| Size | Types |
|------|-------|
| 300mm | Hygenus, Standard Fixed, Self-Adhesive, Budget Fixed |
| 400mm | Hygenus, Standard Fixed |
| 480mm | Hygenus, Standard Fixed |

**Expected variants:** 8 combos x 4 colours (Blue, Green, Red, Yellow) = **32**

> Note: "Standard" and "Standard Fixed" are mutually exclusive — no product uses both.

## Gap Analysis Results

### Standard Line: No gaps

All 49 Standard line products have the full 36 variants. Complete.

### Fixed Line: Gaps found

All 11 Fixed line products with 4 colours (Blue, Green, Red, Yellow) have the full 32 variants. No gaps there.

**5 toilet signs are missing Green and Yellow** (16 of 32 variants):

| Product | ID | Variants | Colours Present |
|---------|----|----------|-----------------|
| Female Toilet Sign | 2430143 | 16 | Blue, Red |
| Female Toilet with Symbol Sign | 2430142 | 16 | Blue, Red |
| Male Toilet Sign | 2430159 | 16 | Blue, Red |
| Male Toilet with Symbol Sign | 2430158 | 16 | Blue, Red |
| Staff Toilet Sign | 2430177 | 16 | Blue, Red |

Each has all 8 size/type combos for Blue and Red but is missing Green and Yellow entirely. Adding both colours would bring each to 32 variants (+16 per product, +80 total).

### Specialty / Interchangeable Signs (excluded from gap analysis)

These use non-standard colour sets or reduced variant matrices — likely intentional:

| Product | ID | Variants | Notes |
|---------|----|----------|-------|
| Interchangeable Bedroom Sign | 2430128 | 30 | 5 colours incl. "Custom" |
| Nurse Sign - Illustrated | 2657758 | 25 | 25 unique colours (Almond, Burgundy, etc.) |
| Interchangeable Bathroom M/F | 2979146 | 12 | Different type set (no Standard/Fixed) |
| Interchangeable Shower Room M/F | 2979151 | 12 | Different type set |
| Interchangeable Shower Room w/ Slider | 2657746 | 6 | Blue + Red only |
| Interchangeable Toilet for Hospitals | 2430144 | 6 | Blue + Red only |
| Shared Bedroom by 2 | 2430129 | 5 | 5 colours incl. "Custom" |

### Outliers (excluded)

| Product | ID | Variants | Notes |
|---------|----|----------|-------|
| Toilet Sign Test | 5585555 | 120 | Test product |
| Kick Plates | 2816448 | 78 | Different product type (12+ colours) |
| Custom Sign | 2763961 | 45 | Custom product |

## Option Values Reference

**Standard 4 colours:** Blue, Green, Red, Yellow
**Standard 3 sizes:** 300mm, 400mm, 480mm
**Standard line types:** Hygenus, Standard, Self-Adhesive, Budget Fixed, Budget Self-Adhesive
**Fixed line types:** Hygenus, Standard Fixed, Self-Adhesive, Budget Fixed

## Recommendation

The 5 toilet signs missing Green and Yellow are the clearest candidates for variant expansion. Each needs 16 new variants (8 size/type combos x 2 colours). All other standard signs are complete.
