<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Sale metadata threaded through the pricing event chain.
 *
 * - For add-to-sale: saleReason required, comments/dates optional
 * - For auto-removal: saleReason + removalReason populated
 */
final readonly class SaleSettings
{
    public function __construct(
        public string $saleReason,
        public ?string $saleComments = null,
        public ?DateTimeImmutable $saleStartDate = null,
        public ?DateTimeImmutable $saleEndDate = null,
        public ?int $saleEndsStock = null,
        public ?SaleRemovalReason $removalReason = null,
    ) {}

    /**
     * Create settings for an automatic sale removal.
     */
    public static function forRemoval(SaleRemovalReason $reason): self
    {
        return new self(
            saleReason: $reason->label(),
            removalReason: $reason,
        );
    }

    /**
     * Reconstruct SaleSettings from typed custom field values.
     *
     * Mirrors {@see self::fromRawCustomFields()} for ProductView callers that
     * hold `list<AbstractCustomFieldValue>` rather than the raw name => value map.
     * Keeps the read path typed — no raw JSONB leakage into the View.
     *
     * @param list<AbstractCustomFieldValue> $typedCustomFields
     */
    public static function fromTypedCustomFields(array $typedCustomFields): ?self
    {
        $reason = self::stringFieldValue($typedCustomFields, SaleCustomField::Reason->value);
        if ($reason === null || $reason === '') {
            return null;
        }

        $comments = self::stringFieldValue($typedCustomFields, SaleCustomField::Comments->value);
        $endsStockRaw = self::stringFieldValue($typedCustomFields, SaleCustomField::EndsStock->value);

        return new self(
            saleReason: $reason,
            saleComments: $comments !== null && $comments !== '' ? $comments : null,
            saleStartDate: self::dateFieldValue($typedCustomFields, SaleCustomField::DateStart->value),
            saleEndDate: self::dateFieldValue($typedCustomFields, SaleCustomField::DateEnd->value),
            saleEndsStock: $endsStockRaw !== null && $endsStockRaw !== '' && \is_numeric($endsStockRaw)
                ? (int) $endsStockRaw
                : null,
        );
    }

    /**
     * Reconstruct SaleSettings from raw custom field data.
     *
     * // REVIEW: Returns null when no sale_reason is present, discarding any other
     * // fields (dates, comments, endsStock). Callers that need partial data must
     * // fall back to a default SaleSettings. Consider extracting all fields regardless.
     *
     * @param array<string, mixed> $rawCustomFields Raw name => value pairs
     */
    public static function fromRawCustomFields(array $rawCustomFields): ?self
    {
        $reason = $rawCustomFields[SaleCustomField::Reason->value] ?? null;
        if (! \is_string($reason) || $reason === '') {
            return null;
        }

        $comments = $rawCustomFields[SaleCustomField::Comments->value] ?? null;
        $endsStock = $rawCustomFields[SaleCustomField::EndsStock->value] ?? null;

        return new self(
            saleReason: $reason,
            saleComments: \is_string($comments) && $comments !== '' ? $comments : null,
            saleStartDate: self::parseDateField($rawCustomFields[SaleCustomField::DateStart->value] ?? null),
            saleEndDate: self::parseDateField($rawCustomFields[SaleCustomField::DateEnd->value] ?? null),
            saleEndsStock: \is_string($endsStock) && $endsStock !== '' && \is_numeric($endsStock)
                ? (int) $endsStock
                : null,
        );
    }

    private static function parseDateField(mixed $value): ?DateTimeImmutable
    {
        if (! \is_string($value) || $value === '') {
            return null;
        }

        $parsed = \date_create_immutable($value);

        return $parsed !== false ? $parsed : null;
    }

    /**
     * Extract a string value from a typed custom field list by name.
     *
     * @param list<AbstractCustomFieldValue> $typedCustomFields
     */
    private static function stringFieldValue(array $typedCustomFields, string $name): ?string
    {
        $field = \array_find(
            $typedCustomFields,
            static fn(AbstractCustomFieldValue $cf): bool => $cf->name() === $name,
        );

        if (! $field instanceof StringCustomFieldValue) {
            return null;
        }

        return $field->value;
    }

    /**
     * Extract a DateTimeImmutable from a typed custom field list by name.
     *
     * Accepts both DateTime-typed fields (direct) and String-typed fields that
     * carry ISO-8601 date strings (parsed). Mirrors {@see self::parseDateField()}
     * for string values.
     *
     * @param list<AbstractCustomFieldValue> $typedCustomFields
     */
    private static function dateFieldValue(array $typedCustomFields, string $name): ?DateTimeImmutable
    {
        $field = \array_find(
            $typedCustomFields,
            static fn(AbstractCustomFieldValue $cf): bool => $cf->name() === $name,
        );

        if ($field instanceof DateTimeCustomFieldValue) {
            return $field->value;
        }

        if ($field instanceof StringCustomFieldValue) {
            return self::parseDateField($field->value);
        }

        return null;
    }

    /**
     * Build the ShopWired custom fields payload from nullable settings.
     *
     * When $settings is null (settings row missing), writes empty/default values
     * so the custom fields block still exists on the product.
     *
     * @return array<string, string>
     */
    public static function toCustomFieldsArray(?self $settings): array
    {
        return [
            SaleCustomField::DateStart->value => $settings?->saleStartDate?->format('Y-m-d')
                ?? (new DateTimeImmutable())->format('Y-m-d'),
            SaleCustomField::Reason->value => $settings !== null ? $settings->saleReason : '',
            SaleCustomField::Comments->value => $settings !== null ? ($settings->saleComments ?? '') : '',
            SaleCustomField::DateEnd->value => $settings?->saleEndDate?->format('Y-m-d') ?? '',
            SaleCustomField::EndsStock->value => $settings?->saleEndsStock !== null
                ? (string) $settings->saleEndsStock
                : '',
        ];
    }

    /**
     * @return array{sale_reason: string, sale_comments: string|null, sale_start_date: string|null, sale_end_date: string|null, sale_ends_stock: int|null, removal_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'sale_reason' => $this->saleReason,
            'sale_comments' => $this->saleComments,
            'sale_start_date' => $this->saleStartDate?->format(DateTimeInterface::ATOM),
            'sale_end_date' => $this->saleEndDate?->format(DateTimeInterface::ATOM),
            'sale_ends_stock' => $this->saleEndsStock,
            'removal_reason' => $this->removalReason?->value,
        ];
    }
}
