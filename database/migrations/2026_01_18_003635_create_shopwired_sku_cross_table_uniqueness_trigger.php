<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates trigger function for cross-table SKU uniqueness between products and variations.
 *
 * PostgreSQL doesn't support cross-table unique constraints natively. This trigger
 * ensures that a SKU cannot exist in both shopwired.products and shopwired.product_variations.
 *
 * The function is placed in utils schema for reusability across schemas.
 */
return new class extends Migration {
    public function up(): void
    {
        // Create trigger function in utils schema
        DB::statement("
            CREATE OR REPLACE FUNCTION utils.check_shopwired_sku_cross_table_uniqueness()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                -- Skip check if SKU is null (only products can have null SKUs)
                IF NEW.sku IS NULL THEN
                    RETURN NEW;
                END IF;

                -- Check the OTHER table for duplicate SKU
                -- NOTE: No ERRCODE specified, defaults to P0001 which becomes
                -- DatabaseOperationFailedException (not DuplicateRecordException).
                -- This ensures cross-table conflicts are treated as sync errors.
                IF TG_TABLE_NAME = 'product_variations' THEN
                    IF EXISTS (SELECT 1 FROM shopwired.products WHERE sku = NEW.sku) THEN
                        RAISE EXCEPTION 'Cross-table SKU conflict: ''%'' already exists in shopwired.products', NEW.sku;
                    END IF;
                ELSIF TG_TABLE_NAME = 'products' THEN
                    IF EXISTS (SELECT 1 FROM shopwired.product_variations WHERE sku = NEW.sku) THEN
                        RAISE EXCEPTION 'Cross-table SKU conflict: ''%'' already exists in shopwired.product_variations', NEW.sku;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$
        ");

        // Apply trigger to product_variations table
        DB::statement('
            CREATE TRIGGER check_variation_sku_cross_table
            BEFORE INSERT OR UPDATE OF sku ON shopwired.product_variations
            FOR EACH ROW
            EXECUTE FUNCTION utils.check_shopwired_sku_cross_table_uniqueness()
        ');

        // Apply trigger to products table
        DB::statement('
            CREATE TRIGGER check_product_sku_cross_table
            BEFORE INSERT OR UPDATE OF sku ON shopwired.products
            FOR EACH ROW
            EXECUTE FUNCTION utils.check_shopwired_sku_cross_table_uniqueness()
        ');
    }

    public function down(): void
    {
        // Remove triggers
        DB::statement('DROP TRIGGER IF EXISTS check_product_sku_cross_table ON shopwired.products');
        DB::statement('DROP TRIGGER IF EXISTS check_variation_sku_cross_table ON shopwired.product_variations');

        // Remove trigger function
        DB::statement('DROP FUNCTION IF EXISTS utils.check_shopwired_sku_cross_table_uniqueness()');
    }
};
