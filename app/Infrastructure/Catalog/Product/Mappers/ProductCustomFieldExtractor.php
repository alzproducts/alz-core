<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;

/**
 * Maps product-specific custom fields to typed Domain values, keeping
 * field-name knowledge out of ProductViewAssembler's orchestration.
 */
final readonly class ProductCustomFieldExtractor
{
    public static function freeDelivery(CustomFieldValueList $typedCustomFields): ?FreeDeliveryType
    {
        $value = $typedCustomFields->stringByName('free_delivery');

        return $value !== null ? FreeDeliveryType::tryFrom($value) : null;
    }
}
