# Implementation Log: #352 First Public API Endpoint — GET /api/products

**Plan Document**: `.ai/plans/2026-03-24_352-first-public-api-endpoint-get-products.md`
**Status**: Complete
**Started**: 2026-03-24
**Completed**: 2026-03-24

## Decision Log
- **Mapper strategy**: Option A (two mapper methods) — `toReadDomain()` alongside existing `toDomain()`
- **Include validation**: Integrated into Spatie Data request DTO, not standalone trait
- **PaginatedListDTO**: Framework-free DTO in Application layer (covariant template), reconstructed in Presentation
- **Route group**: New `auth.supabase` middleware group section in routes/api.php

## Deviations from Plan
- `PaginatedList` → `PaginatedListDTO` (PHPArkitect naming convention enforcement)
- `ApiExceptionRenderer` → `ApiExceptionMapper` (PHPArkitect Presentation layer suffix)
- `BuildsPaginatedResponse` → `BuildsPaginatedResponseTrait` (PHPStan Symplify trait naming + PHPArkitect)
- `@template T` → `@template-covariant T` on PaginatedListDTO (PHPStan variance — safe because readonly DTO)

## Implementation Progress

### Part 1: Foundational Infrastructure
- [x] `PaginatedListDTO` (Application)
- [x] `ApiExceptionMapper` (Presentation)
- [x] `BuildsPaginatedResponseTrait` (Presentation)

### Part 2: Product List Endpoint
- [x] `ListProductsUseCase` (Application/Catalog)
- [x] `ProductRepositoryInterface::paginate()` (Application contract)
- [x] `EloquentProductRepository::paginate()` (Infrastructure)
- [x] `ProductModelMapper::toReadDomain()` (Infrastructure)
- [x] `ListProductsRequestDTO` (Presentation)
- [x] `ProductResource` + `ProductVariationResource` (Presentation)
- [x] `ProductController` (Presentation)
- [x] Route wiring (routes/api.php)
- [x] Exception mapper registration (bootstrap/app.php)

### Quality
- [x] `make test` — 2585 passed, 1 skipped (pre-existing)
- [x] `make lint` — All 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- [x] Simplify review — fixed 5 issues
- [x] Sweep checklist — fixed 1 issue (business logging)

## Simplify Fixes
1. `withQueryString()` return value was discarded — pagination links now work
2. Missing `@throws DuplicateRecordException` propagated through interface → use case → controller
3. Simplified identity `$includeMap` to `array_intersect()`
4. `rawCustomFields`/`rawFilters` set to `[]` in `toReadDomain()` (storage-only fields)
5. Removed noise comment

## Sweep Fixes
1. Added business logging to `ListProductsUseCase` (PSR-3 LoggerInterface)

## PR Notes

### What
First consumer-facing API endpoint: `GET /api/products` with JSON error envelope, pagination, and `?include=variations` support.

### Why
Frontend application needs a domain-centric product catalog API. Foundational infrastructure (exception mapping, pagination DTO, response trait) supports all future API endpoints.

### Key Decisions
- Two mapper methods (`toDomain()` + `toReadDomain()`) to avoid 4 unnecessary DB queries per API request
- `PaginatedListDTO` bridges Application/Presentation Deptrac boundary (no `Illuminate\Pagination` in Application)
- `ApiExceptionMapper` registered globally with `expectsJson()` guard — zero overhead on non-API routes
- New `auth.supabase` route group (JWT + approval + RLS) for consumer API routes

### Testing
- 2585 existing tests pass, no regressions
- All 5 linters clean
- Manual verification: auth, pagination, includes, validation (outlined in plan verification section)
