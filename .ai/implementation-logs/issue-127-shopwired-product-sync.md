# Implementation Log: Issue #127 - ShopWired Product Sync

## Overview
Implementing full product synchronization from ShopWired API to local database, including variations, images, and pricing data.

## Decision Log

### 2026-01-16: Initial Implementation Structure
- **Decision**: Follow established patterns from `SyncShopwiredCustomersJob` and `SyncShopwiredOrdersJob`
- **Rationale**: Consistency with existing codebase, proven patterns for large-scale sync operations

### 2026-01-16: Domain Layer Placement
- **Decision**: Place Product value objects under `App\Domain\Catalog\Product\ValueObjects\`
- **Rationale**: Products are part of the catalog domain, alongside existing Category value objects

### 2026-01-16: Images Storage Strategy
- **Decision**: Store images as JSONB array on products table (no separate join table)
- **Rationale**: Simpler, no joins needed, images always fetched with product. Low cardinality (~3-5 images per product)

### 2026-01-16: Variation Sync Strategy
- **Decision**: Delete+insert pattern for variations (not upsert)
- **Rationale**: Simpler than diffing, idempotent, handles variation option changes cleanly

### 2026-01-18: Repository vs Client Architecture
- **Decision**: `ProductRepository` returns fully-hydrated Domain objects with typed custom fields
- **Rationale**: Consumers should use Repository for data retrieval (treats products as "local" entities). Custom field interpretation happens on read, in the Repository (using `ProductDomainFactory`).
- **Deferred**: Broader question of whether Clients should return DTOs vs Domain objects is noted as future consideration, not blocking this feature.

## Implementation Progress

### Phase 1: Domain Layer ✅
- [x] ProductImage value object
- [x] ProductVariationOption value object
- [x] ProductVariation value object (with Gtin)
- [x] Product value object
- [x] Gtin value object (bonus - barcode validation)
- [x] InvalidGtinException

### Phase 2: Database Migrations
- [ ] shopwired.products table
- [ ] shopwired.product_variations table

### Phase 3: Application Contracts
- [ ] ProductClientInterface
- [ ] ProductRepositoryInterface

### Phase 4: Infrastructure DTOs
- [ ] ProductImageResponse
- [ ] ProductVariationOptionResponse
- [ ] ProductVariationResponse
- [ ] ProductResponse

### Phase 5: Infrastructure Persistence
- [ ] ProductModel
- [ ] ProductVariationModel
- [ ] ProductModelMapper
- [ ] EloquentProductRepository

### Phase 6: Infrastructure Client
- [ ] ProductClient

### Phase 7: Application Use Case
- [ ] SyncProductsUseCase

### Phase 8: Presentation Jobs
- [ ] SyncShopwiredProductsJob
- [ ] ReconcileShopwiredProductsJob

### Phase 9: Service Provider Wiring
- [ ] ShopwiredClientFactory updates
- [ ] ShopwiredServiceProvider bindings

## Post-Merge Tasks
After merging changes from other worktree:
- [ ] Replace `?float $weight` with `Weight` value object in `ProductVariation` and `Product`

## PR Notes
_To be filled when creating PR_

## Blockers / Questions
_None currently_
