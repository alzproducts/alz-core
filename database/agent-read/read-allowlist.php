<?php

declare(strict_types=1);

/*
 * Read allowlist: the only relations and columns the Agent database login may read, as schema.relation => columns.
 * Rules: docs/guides/agent-production-reads.md; AgentReadAllowlistRules enforces them, AgentReadGrantSync applies
 * this file after every migrate. A column or relation added later stays unreadable until it is listed here.
 */
return [
    'catalog.cost_price_changes' => [
        'id', 'sku', 'supplier_id', 'supplier_name', 'old_cost_price', 'new_cost_price',
        'changed_at',
    ],
    'catalog.credit_product_popularity_ranking' => [
        'algorithm_version', 'parent_external_id', 'sku', 'title', 'is_active', 'calculated_sort_order',
        'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover', 'main_qty_rank',
        'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score', 'final_score',
        'trend', 'credit_tier',
    ],
    'catalog.credit_product_popularity_ranking_config' => [
        'algorithm_version', 'main_period_interval', 'recent_period_interval', 'w_main', 'w_recent', 'w_qty',
        'w_turnover', 'max_rank', 'tier_1_size', 'tier_2_size', 'is_active', 'created_at',
    ],
    'catalog.credit_product_popularity_ranking_latest' => [
        'snapshot_date', 'algorithm_version', 'parent_external_id', 'sku', 'title', 'is_active',
        'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover',
        'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score',
        'final_score', 'trend', 'credit_tier', 'created_at', 'updated_at',
    ],
    'catalog.credit_product_popularity_snapshots' => [
        'snapshot_date', 'algorithm_version', 'parent_external_id', 'sku', 'title', 'is_active',
        'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover',
        'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score',
        'final_score', 'trend', 'credit_tier', 'created_at', 'updated_at',
    ],
    'catalog.custom_field_general_settings' => [
        'id', 'custom_field_definition_id', 'tooltip', 'select_type', 'suggest_common_data', 'admin_only',
        'field_validation_rule', 'created_at', 'updated_at',
    ],
    'catalog.custom_field_product_settings' => [
        'id', 'custom_field_definition_id', 'stock_item_update_mode', 'created_at', 'updated_at',
    ],
    'catalog.customers_view' => [
        'id', 'external_id', 'is_trade', 'is_active', 'created_at',
    ],
    'catalog.free_delivery_shipping_costs' => [
        'delivery_type', 'cost',
    ],
    'catalog.margin_tier_thresholds' => [
        'id', 'low_max_pct', 'standard_max_pct', 'updated_at',
    ],
    'catalog.orders_view' => [
        'id', 'external_id', 'reference', 'placed_at', 'total', 'status_id',
        'status_name', 'status_type', 'status_sort_order', 'lifecycle_status', 'customer_id',
    ],
    'catalog.product_extra_data' => [
        'id', 'sku', 'rrp', 'created_at', 'updated_at',
    ],
    'catalog.product_list_fragments' => [
        'product_id', 'shape', 'fingerprint', 'body', 'built_at',
    ],
    'catalog.product_popularity_ranking' => [
        'algorithm_version', 'parent_external_id', 'sku', 'title', 'is_active', 'calculated_sort_order',
        'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover', 'main_qty_rank',
        'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score', 'final_score',
        'trend',
    ],
    'catalog.product_popularity_ranking_config' => [
        'algorithm_version', 'main_period_interval', 'recent_period_interval', 'w_main', 'w_recent', 'w_qty',
        'w_turnover', 'max_rank', 'is_active', 'created_at',
    ],
    'catalog.product_popularity_ranking_latest' => [
        'snapshot_date', 'algorithm_version', 'parent_external_id', 'sku', 'title', 'is_active',
        'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover',
        'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score',
        'final_score', 'trend', 'created_at', 'updated_at',
    ],
    'catalog.product_popularity_snapshots' => [
        'snapshot_date', 'algorithm_version', 'parent_external_id', 'sku', 'title', 'is_active',
        'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover',
        'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score',
        'final_score', 'trend', 'created_at', 'updated_at',
    ],
    'catalog.product_variations_view' => [
        'id', 'product_id', 'product_external_id', 'external_id', 'sku', 'stock',
        'available_stock', 'physical_stock', 'weight', 'gtin', 'mpn', 'image_index',
        'options', 'created_at', 'updated_at', 'raw_price', 'raw_sale_price', 'price',
        'sale_price', 'is_on_sale', 'effective_price', 'cost_price', 'profit_margin', 'popularity_rank',
        'popularity_max', 'default_supplier_name', 'stock_value', 'net_margin_single_unit', 'variation_title', 'parent_is_active',
        'parent_has_free_delivery', 'parent_main_category_ids',
    ],
    'catalog.products_view' => [
        'id', 'external_id', 'sku', 'gtin', 'title', 'description',
        'slug', 'url', 'price', 'sale_price', 'compare_price', 'stock',
        'available_stock', 'physical_stock', 'is_active', 'vat_exclusive', 'vat_relief', 'weight',
        'meta_title', 'meta_description', 'category_ids', 'images', 'custom_fields', 'filters',
        'sort_order', 'shopwired_created_at', 'shopwired_updated_at', 'created_at', 'updated_at', 'is_on_sale',
        'has_any_sale', 'effective_price', 'cost_price', 'profit_margin', 'has_free_delivery', 'main_category_ids',
        'popularity_rank', 'popularity_max', 'profit_margin_min', 'profit_margin_max', 'net_margin_single_unit_min', 'net_margin_single_unit_max',
        'price_last_updated_at', 'cost_price_last_updated_at',
    ],
    'catalog.products_with_changed_offers_filters' => [
        'product_id', 'desired_filter_values',
    ],
    'catalog.products_with_changed_rating_filters' => [
        'product_id', 'desired_filter_values',
    ],
    'catalog.products_with_changed_ratings' => [
        'product_id', 'new_average', 'new_count',
    ],
    'catalog.products_with_changed_shipping_offers_filters' => [
        'product_id', 'desired_filter_values',
    ],
    'catalog.products_with_changed_shipping_options_filters' => [
        'product_id', 'desired_filter_values',
    ],
    'catalog.products_with_changed_vat_relief_filters' => [
        'product_id', 'desired_filter_values',
    ],
    'catalog.related_products_algorithm_params' => [
        'id', 'algorithm_version', 'category_weight', 'title_weight', 'popularity_weight', 'max_results',
        'min_content_score', 'default_popularity', 'exclude_compare_list', 'is_active', 'created_at',
    ],
    'catalog.sku_aliases' => [
        'live_sku', 'map_sku',
    ],
    'catalog.sku_popularity_ranking' => [
        'algorithm_version', 'live_sku', 'parent_external_id', 'variation_external_id', 'title', 'is_active',
        'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty', 'recent_turnover',
        'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score', 'recent_score',
        'final_score', 'trend',
    ],
    'catalog.sku_popularity_ranking_config' => [
        'algorithm_version', 'main_period_interval', 'recent_period_interval', 'w_main', 'w_recent', 'w_qty',
        'w_turnover', 'max_rank', 'is_active', 'created_at',
    ],
    'catalog.sku_popularity_ranking_latest' => [
        'snapshot_date', 'algorithm_version', 'live_sku', 'parent_external_id', 'variation_external_id', 'title',
        'is_active', 'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty',
        'recent_turnover', 'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score',
        'recent_score', 'final_score', 'trend', 'created_at', 'updated_at',
    ],
    'catalog.sku_popularity_snapshots' => [
        'snapshot_date', 'algorithm_version', 'live_sku', 'parent_external_id', 'variation_external_id', 'title',
        'is_active', 'calculated_sort_order', 'current_sort_order', 'main_qty', 'main_turnover', 'recent_qty',
        'recent_turnover', 'main_qty_rank', 'main_turnover_rank', 'recent_qty_rank', 'recent_turnover_rank', 'main_score',
        'recent_score', 'final_score', 'trend', 'created_at', 'updated_at',
    ],
    'checkout.basket_snapshots' => [
        'id', 'basket_total', 'shipping_method_id', 'delivery_date', 'created_at',
    ],
    'customer_service.call_tracking_actions' => [
        'id', 'call_tracking_visit_id', 'ad_platform', 'status', 'external_id', 'error_message',
        'attempts', 'created_at', 'updated_at', 'processing_started_at', 'completed_at',
    ],
    'customer_service.call_tracking_calls' => [
        'id', 'tracking_number_dialled', 'helpscout_conversation_id', 'call_status', 'created_at', 'call_sid',
        'updated_at',
    ],
    'customer_service.call_tracking_numbers' => [
        'id', 'active', 'sort_order', 'created_at', 'updated_at',
    ],
    'customer_service.call_tracking_rotation_counter' => [
        'id', 'counter', 'updated_at',
    ],
    'customer_service.call_tracking_visits' => [
        'id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'marketing_consent_granted', 'tracking_number_shown', 'created_at',
    ],
    'customer_service.contact_submission_actions' => [
        'id', 'contact_submission_id', 'action_type', 'status', 'external_id', 'error_message',
        'attempts', 'created_at', 'updated_at', 'processing_started_at', 'completed_at', 'ad_platform',
        'next_attempt_at', 'quote_value_net', 'quote_converted_at',
    ],
    'linnworks.order_extended_properties' => [
        'id', 'linnworks_order_id', 'row_id', 'name', 'type', 'create_date',
        'last_update', 'created_at', 'updated_at',
    ],
    'linnworks.order_items' => [
        'id', 'linnworks_order_id', 'row_id', 'parent_item_id', 'stock_item_id', 'stock_item_int_id',
        'item_number', 'sku', 'item_source', 'title', 'category_id', 'category_name',
        'quantity', 'price_per_unit', 'unit_cost', 'despatch_stock_unit_cost', 'discount', 'tax_rate',
        'cost', 'cost_inc_tax', 'sales_tax', 'tax_cost_inclusive', 'discount_value', 'weight',
        'barcode_number', 'channel_sku', 'channel_title', 'batch_number_scan_required', 'serial_number_scan_required', 'is_service',
        'is_unlinked', 'added_date', 'created_at', 'updated_at',
    ],
    'linnworks.orders' => [
        'id', 'linnworks_order_id', 'num_order_id', 'processed', 'last_updated', 'processed_on',
        'paid_on', 'received_date', 'reference_num', 'external_reference_num', 'secondary_reference', 'status',
        'is_cancelled', 'hold_or_cancel', 'marker', 'is_parked', 'source', 'sub_source',
        'despatch_by_date', 'fulfilment_location_id', 'location', 'total_charge', 'subtotal', 'tax',
        'payment_method', 'payment_method_id', 'currency', 'postal_service_name', 'vendor', 'postage_cost',
        'postage_cost_ex_tax', 'ship_country', 'bill_country', 'created_at', 'updated_at',
    ],
    'linnworks.purchase_order_additional_costs' => [
        'id', 'linnworks_purchase_id', 'linnworks_additional_cost_item_id', 'additional_cost_type_id', 'reference', 'sub_total_line_cost',
        'tax_rate', 'tax', 'currency', 'conversion_rate', 'total_line_cost', 'allocation_locked',
        'additional_cost_type_name', 'additional_cost_type_is_shipping_type', 'additional_cost_type_is_partial_allocation', 'print', 'allocation_method', 'created_at',
        'updated_at',
    ],
    'linnworks.purchase_order_delivered_records' => [
        'id', 'linnworks_purchase_id', 'linnworks_delivery_record_id', 'fk_purchase_item_id', 'fk_stock_location_id', 'unit_cost',
        'delivered_quantity', 'created_date_time', 'created_at', 'updated_at',
    ],
    'linnworks.purchase_order_extended_properties' => [
        'id', 'linnworks_purchase_id', 'row_id', 'property_name', 'property_value', 'added_date_time',
        'created_at', 'updated_at',
    ],
    'linnworks.purchase_order_items' => [
        'id', 'linnworks_purchase_id', 'linnworks_purchase_item_id', 'fk_stock_item_id', 'quantity', 'delivered',
        'pack_quantity', 'pack_size', 'cost', 'tax', 'tax_rate', 'sort_order',
        'created_at', 'updated_at',
    ],
    'linnworks.purchase_order_notes' => [
        'id', 'linnworks_purchase_id', 'linnworks_purchase_order_note_id', 'date_time', 'created_at', 'updated_at',
    ],
    'linnworks.purchase_orders' => [
        'id', 'linnworks_purchase_id', 'fk_supplier_id', 'fk_location_id', 'external_invoice_number', 'status',
        'locked', 'line_count', 'delivered_lines_count', 'currency', 'supplier_reference_number', 'unit_amount_tax_included_type',
        'postage_paid', 'total_cost', 'tax_paid', 'shipping_tax_rate', 'conversion_rate', 'converted_shipping_cost',
        'converted_shipping_tax', 'converted_other_cost', 'converted_other_tax', 'converted_grand_total', 'date_of_purchase', 'date_of_delivery',
        'quoted_delivery_date', 'note_count', 'synced_at', 'created_at', 'updated_at',
    ],
    'linnworks.stock_item_extended_properties' => [
        'id', 'stock_item_id', 'pk_row_id', 'property_name', 'property_value', 'property_type',
        'created_at', 'updated_at',
    ],
    'linnworks.stock_item_suppliers' => [
        'id', 'stock_item_id', 'supplier_id', 'supplier_name', 'code', 'supplier_barcode',
        'purchase_price', 'is_default', 'lead_time', 'supplier_currency', 'min_price', 'max_price',
        'average_price', 'created_at', 'updated_at', 'average_lead_time', 'supplier_min_order_qty', 'supplier_pack_size',
    ],
    'linnworks.stock_items' => [
        'id', 'stock_item_id', 'item_number', 'item_title', 'barcode', 'quantity',
        'available', 'in_order', 'due', 'minimum_level', 'purchase_price', 'retail_price',
        'tax_rate', 'weight', 'weight_unit', 'height', 'width', 'depth',
        'is_composite', 'linnworks_created_at', 'created_at', 'updated_at', 'category_id', 'category_name',
        'jit', 'is_archived', 'is_logically_deleted',
    ],
    'linnworks.suppliers' => [
        'id', 'pk_supplier_id', 'supplier_name', 'country', 'web_page', 'currency',
        'created_at', 'updated_at',
    ],
    'marketing.potential_conversion_annotations' => [
        'id', 'source_id', 'is_potential_quote', 'quoted_at', 'dismissed_at', 'created_at',
        'updated_at',
    ],
    'marketing.potential_conversions_view' => [
        'id', 'source', 'reason', 'customer_type', 'order_number', 'quantity',
        'shopwired_customer_id', 'utm_source', 'utm_medium', 'utm_campaign', 'created_at', 'is_potential_quote',
        'quoted_at', 'lead_status', 'quote_status', 'helpscout_external_id', 'dismissed_at', 'has_ad_id',
    ],
    'operations.price_periods' => [
        'id', 'sku', 'base_price_gross', 'sale_price_gross', 'effective_price_gross', 'price_has_tax',
        'effective_from', 'effective_to', 'created_at',
    ],
    'operations.sku_changes' => [
        'id', 'old_sku', 'new_sku', 'reason', 'error_message', 'created_at',
        'completed_at',
    ],
    'quickbooks.mappings' => [
        'id', 'key', 'qbo_entity', 'qbo_ref_id', 'qbo_ref_name', 'realm_id',
        'resolved_at', 'created_at', 'updated_at',
    ],
    'quickbooks.order_sync_errors' => [
        'id', 'order_sync_id', 'trigger', 'run_id', 'reason_code', 'qbo_fault_code',
        'qbo_fault_element', 'message', 'created_at',
    ],
    'quickbooks.order_sync_runs' => [
        'id', 'trigger', 'force_retry', 'range_from', 'range_to', 'order_references',
        'status', 'candidate_count', 'synced', 'already_synced', 'skipped', 'failed',
        'not_attempted', 'open_jobs', 'job_failed', 'requested_at', 'started_at', 'finished_at',
    ],
    'quickbooks.order_sync_source' => [
        'reference', 'external_id', 'order_placed_at', 'lifecycle_status', 'payment_method_raw', 'billing_country',
        'delivery_country', 'shipping_method', 'shipping_total_net', 'shipping_vat_rate', 'transaction_id',
    ],
    'quickbooks.order_syncs' => [
        'id', 'realm_id', 'reference', 'order_external_id', 'doc_type', 'status',
        'qbo_txn_id', 'qbo_doc_number', 'attempts', 'last_attempted_at', 'last_error_code', 'last_error_message',
        'lease_expires_at', 'payload_hash', 'synced_at', 'created_at', 'updated_at',
    ],
    'reviews_io.product_ratings' => [
        'id', 'sku', 'average_rating', 'num_ratings', 'created_at', 'updated_at',
    ],
    'shopwired.brands' => [
        'id', 'external_id', 'shopwired_created_at', 'title', 'description', 'slug',
        'url', 'active', 'featured', 'sort_order', 'meta_title', 'meta_description',
        'meta_keywords', 'image_url', 'custom_fields', 'created_at', 'updated_at',
    ],
    'shopwired.categories' => [
        'id', 'external_id', 'shopwired_created_at', 'title', 'description', 'description2',
        'slug', 'url', 'active', 'featured', 'trade_only', 'sort_order',
        'meta_title', 'meta_description', 'meta_keywords', 'meta_no_index', 'image_url', 'parent_ids',
        'custom_fields', 'created_at', 'updated_at',
    ],
    'shopwired.custom_field_definitions' => [
        'id', 'external_id', 'name', 'type', 'label', 'item_type',
        'sort_order', 'allowed_values', 'created_at', 'updated_at',
    ],
    'shopwired.customers' => [
        'id', 'external_id', 'is_trade', 'is_active', 'is_credit_enabled', 'accepts_marketing',
        'shopwired_created_at', 'created_at', 'updated_at',
    ],
    'shopwired.filter_groups' => [
        'id', 'external_id', 'title', 'option_no', 'sort_order', 'created_at',
        'updated_at',
    ],
    'shopwired.order_admin_comments' => [
        'id', 'order_id', 'order_external_id', 'external_id', 'status_id', 'created_at_shopwired',
        'created_at', 'updated_at',
    ],
    'shopwired.order_discounts' => [
        'id', 'order_id', 'order_external_id', 'name', 'value', 'type',
        'voucher_id', 'offer_id', 'created_at', 'updated_at',
    ],
    'shopwired.order_product_extra_data' => [
        'id', 'order_external_id', 'external_id', 'variation_hash', 'sku_override', 'created_at',
        'updated_at',
    ],
    'shopwired.order_products' => [
        'id', 'order_id', 'order_external_id', 'external_id', 'title', 'sku',
        'price', 'price_vat', 'total', 'total_vat', 'original_price', 'cost_price',
        'quantity', 'vat_rate', 'created_at', 'updated_at', 'is_preorder', 'preorder_date',
        'variation_hash',
    ],
    'shopwired.order_products_resolved' => [
        'id', 'order_id', 'order_external_id', 'external_id', 'title', 'sku',
        'price', 'price_vat', 'total', 'total_vat', 'original_price', 'cost_price',
        'quantity', 'vat_rate', 'is_preorder', 'preorder_date', 'variation_hash', 'created_at',
        'updated_at',
    ],
    'shopwired.order_refunds' => [
        'id', 'order_id', 'order_external_id', 'external_id', 'name', 'value',
        'created_at_shopwired', 'created_at', 'updated_at',
    ],
    'shopwired.orders' => [
        'id', 'external_id', 'reference', 'total', 'sub_total_net', 'shipping_total_net',
        'status_id', 'status_name', 'status_type', 'lifecycle_status', 'customer_id', 'customer_type',
        'billing_country', 'delivery_country', 'shipping_method', 'shipping_charge_net', 'shipping_vat_rate', 'payment_method',
        'marketing', 'has_vat_relief', 'order_placed_at', 'created_at', 'updated_at', 'status_sort_order',
        'is_archived', 'is_anonymized', 'shipping_id', 'original_shipping_total_net', 'transaction_id', 'delivery_date',
        'package_weight', 'tax_value', 'line_item_vat_calculation', 'billing_country_id', 'delivery_country_id', 'pre_order_status',
        'payment_method_raw',
    ],
    'shopwired.orders_deduplicated' => [
        'id', 'external_id', 'reference', 'total', 'sub_total_net', 'shipping_total_net',
        'status_id', 'status_name', 'status_type', 'lifecycle_status', 'customer_id', 'customer_type',
        'billing_country', 'delivery_country', 'shipping_method', 'shipping_charge_net', 'shipping_vat_rate', 'payment_method',
        'marketing', 'has_vat_relief', 'order_placed_at', 'created_at', 'updated_at', 'status_sort_order',
        'is_archived', 'is_anonymized', 'shipping_id', 'original_shipping_total_net', 'transaction_id', 'delivery_date',
        'package_weight', 'tax_value', 'line_item_vat_calculation', 'billing_country_id', 'delivery_country_id', 'pre_order_status',
        'payment_method_raw',
    ],
    'shopwired.product_sale_settings' => [
        'id', 'product_external_id', 'sale_reason', 'sale_start_date', 'sale_end_date', 'sale_ends_stock',
        'created_at', 'updated_at',
    ],
    'shopwired.product_variations' => [
        'id', 'product_id', 'product_external_id', 'external_id', 'sku', 'price',
        'cost_price', 'sale_price', 'stock', 'weight', 'gtin', 'mpn',
        'image_index', 'options', 'created_at', 'updated_at',
    ],
    'shopwired.products' => [
        'id', 'external_id', 'sku', 'title', 'description', 'slug',
        'url', 'price', 'cost_price', 'sale_price', 'compare_price', 'stock',
        'is_active', 'vat_exclusive', 'vat_relief', 'weight', 'meta_title', 'meta_description',
        'gtin', 'category_ids', 'images', 'custom_fields', 'shopwired_created_at', 'shopwired_updated_at',
        'created_at', 'updated_at', 'filters', 'sort_order',
    ],
    'shopwired.webhook_events' => [
        'id', 'subject_id', 'topic', 'webhook_id', 'event_time', 'created_at',
        'updated_at',
    ],
];
