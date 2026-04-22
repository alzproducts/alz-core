<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Source of values for a select-style custom field (local override).
 *
 * Layered on top of a ShopWired definition to tell the frontend where
 * dropdown options should come from when the definition itself doesn't
 * fully describe the source. Deliberately named with the longer
 * "CustomFieldValue…" prefix to avoid reader collision with the
 * adjacent CustomFieldType / CustomFieldItemType enums in this namespace.
 */
enum CustomFieldValueSelectType: string
{
    case Category = 'category';
    case Brand = 'brand';
    case Product = 'product';
}
