<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use DateTimeImmutable;

/**
 * Pulls individual typed values out of a product's resolved custom-field list.
 *
 * Extracted from ProductViewAssembler so the assembler stays focused on orchestration:
 * these helpers operate solely on the already-typed custom-field collection, a distinct
 * concern from include checks and relation guards.
 */
final readonly class ProductCustomFieldExtractor
{
    /** @param list<AbstractCustomFieldValue> $typedCustomFields */
    public static function string(array $typedCustomFields, string $fieldName): ?string
    {
        $field = \array_find($typedCustomFields, static fn(AbstractCustomFieldValue $cf): bool => $cf->name() === $fieldName);

        if ($field === null) {
            return null;
        }

        $value = $field->rawValue();

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** @param list<AbstractCustomFieldValue> $typedCustomFields */
    public static function dateTime(array $typedCustomFields, string $fieldName): ?DateTimeImmutable
    {
        $field = \array_find($typedCustomFields, static fn(AbstractCustomFieldValue $cf): bool => $cf->name() === $fieldName);

        if ($field === null) {
            return null;
        }

        $value = $field->rawValue();

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /** @param list<AbstractCustomFieldValue> $typedCustomFields */
    public static function freeDelivery(array $typedCustomFields): ?FreeDeliveryType
    {
        $value = self::string($typedCustomFields, 'free_delivery');

        return $value !== null ? FreeDeliveryType::tryFrom($value) : null;
    }
}
