<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Exceptions;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Exceptions\DomainException;
use Override;

/**
 * Attempted to write product-specific settings to a non-product custom field.
 *
 * Product settings (e.g. stock-item update scope) only apply when the definition's
 * item_type is Product. Writes targeting other item types (category, brand, etc.)
 * are rejected at the Application layer before touching the database.
 *
 * Presentation maps this to HTTP 422 with `code: product_settings_not_applicable`.
 */
final class ProductSettingsNotApplicableException extends DomainException
{
    public const string CODE = 'product_settings_not_applicable';

    public function __construct(
        public readonly int $definitionExternalId,
        public readonly CustomFieldItemType $itemType,
    ) {
        parent::__construct('Product settings are not applicable to this custom field definition');
    }

    /**
     * @return array{code: string, definition_id: int, item_type: string}
     */
    #[Override]
    public function context(): array
    {
        return [
            'code' => self::CODE,
            'definition_id' => $this->definitionExternalId,
            'item_type' => $this->itemType->value,
        ];
    }
}
