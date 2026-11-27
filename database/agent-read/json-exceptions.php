<?php

declare(strict_types=1);

/*
 * JSON exception list: the json/jsonb columns the Read allowlist may name, as schema.relation.column. Product and
 * catalogue JSON only; no column of an order, customer, basket, contact or call relation (AgentReadAllowlistRules).
 */
return [
    'catalog.product_variations_view.options',
    'catalog.product_variations_view.parent_main_category_ids',
    'catalog.products_view.category_ids',
    'catalog.products_view.custom_fields',
    'catalog.products_view.filters',
    'catalog.products_view.images',
    'catalog.products_view.main_category_ids',
    'catalog.products_with_changed_offers_filters.desired_filter_values',
    'catalog.products_with_changed_shipping_offers_filters.desired_filter_values',
    'catalog.products_with_changed_shipping_options_filters.desired_filter_values',
    'shopwired.brands.custom_fields',
    'shopwired.categories.custom_fields',
    'shopwired.categories.parent_ids',
    'shopwired.custom_field_definitions.allowed_values',
    'shopwired.product_variations.options',
    'shopwired.products.category_ids',
    'shopwired.products.custom_fields',
    'shopwired.products.filters',
    'shopwired.products.images',
];
