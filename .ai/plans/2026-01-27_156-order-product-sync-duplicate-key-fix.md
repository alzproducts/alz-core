# Plan: Fix Order Product Sync Duplicate Key Violation

## Problem

Orders with multiple variations of the same product (e.g., "Magiplug - Basin" + "Magiplug - Kitchen Sink") fail to sync because:
- ShopWired's `external_id` is the **product ID**, not a line item ID
- Multiple line items can share the same `external_id` when customer orders different variations
- Current unique constraint `(order_external_id, external_id)` causes PostgreSQL `ON CONFLICT DO UPDATE` to fail

**31 orders failed** in the 3-month sync due to this issue.

## Solution

Align `syncProducts()` with the existing pattern used for discounts/refunds/comments: **delete all, then insert** (no unique constraint needed).

## Changes

### 1. New Migration: Drop Unique Constraint
**File**: `database/migrations/{timestamp}_drop_unique_constraint_on_shopwired_order_products.php`

```php
Schema::table('shopwired.order_products', function (Blueprint $table) {
    $table->dropUnique(['order_external_id', 'external_id']);
});
```

### 2. Update Repository: Change Sync Strategy
**File**: `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php`

Change `syncProducts()` (lines 171-216) from:
- Delete products NOT in current list → Upsert remaining

To:
- Delete ALL products for order → Insert all (same pattern as `syncDiscounts()`)

### 3. Update Documentation

**a) OrderProductModel** (`app/Infrastructure/Shopwired/Models/OrderProductModel.php`)
- Line 24: `@property int $external_id` → Add clarification that this is the ShopWired **product ID**, not a unique line item identifier. Multiple line items can share this ID when ordering product variations.

**b) OrderProduct Domain VO** (`app/Domain/Catalog/Order/ValueObjects/OrderProduct.php`)
- Line 27: `public int $id` → Add `@param` docblock clarifying this is the ShopWired product ID, not a line item ID.

**c) Models CLAUDE.md** (`app/Infrastructure/Shopwired/Models/CLAUDE.md`)
- Update "Child Table Relationships" section: Remove guidance about composite unique on `(order_external_id, external_id)` for order products specifically, noting the exception.

## Files to Modify

| File | Change |
|------|--------|
| `database/migrations/*_drop_unique_constraint_on_shopwired_order_products.php` | NEW - drop constraint |
| `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` | Simplify `syncProducts()` |
| `app/Infrastructure/Shopwired/Models/OrderProductModel.php` | Doc update |
| `app/Domain/Catalog/Order/ValueObjects/OrderProduct.php` | Doc update |
| `app/Infrastructure/Shopwired/Models/CLAUDE.md` | Doc update |

## Verification

1. **Run migration**: `php artisan migrate`
2. **Re-sync failed orders**: Dispatch job for 3-month range
3. **Verify counts**: Check that order count matches expected (~2774)
4. **Spot check**: Query one of the previously-failed orders (e.g., 11179568) to verify products saved correctly
5. **Run tests**: `make test` to ensure no regressions
