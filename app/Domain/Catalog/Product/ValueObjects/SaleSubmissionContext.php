<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use DateTimeImmutable;

/**
 * Transient snapshot of sale context captured at removal time.
 *
 * Created in UpdateProductPricesUseCase before the DB row is deleted,
 * threaded through ProductPricingUpdatedEvent to the Slack listener.
 * Not persisted — carries just enough context for the removal notification.
 */
final readonly class SaleSubmissionContext
{
    public function __construct(
        public SaleRemovalReason $removalReason,
        public ?string $saleReason = null,
        public ?DateTimeImmutable $saleEndDate = null,
        public ?int $saleEndsStock = null,
    ) {}

    /**
     * Create a removal snapshot from the existing persisted sale settings.
     */
    public static function fromSaleSettings(SaleSettings $settings, SaleRemovalReason $reason): self
    {
        return new self(
            removalReason: $reason,
            saleReason: $settings->saleReason !== '' ? $settings->saleReason : null,
            saleEndDate: $settings->saleEndDate,
            saleEndsStock: $settings->saleEndsStock,
        );
    }
}
