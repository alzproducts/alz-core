<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

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
     * Build the ShopWired custom fields payload from nullable settings.
     *
     * When $settings is null (settings row missing), writes empty/default values
     * so the custom fields block still exists on the product.
     *
     * @return array<string, string>
     */
    public static function toCustomFieldsArray(?self $settings, ?int $defaultSortOrder): array
    {
        return [
            SaleCustomField::DateStart->value => $settings?->saleStartDate?->format('Y-m-d')
                ?? (new DateTimeImmutable())->format('Y-m-d'),
            SaleCustomField::DefaultSortOrder->value => (string) ($defaultSortOrder ?? ''),
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
