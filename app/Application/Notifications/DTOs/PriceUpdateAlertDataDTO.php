<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTOs;

use App\Application\Contracts\ChatNotificationInterface;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\SaleSubmissionContext;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\ValueObjects\IntId;

/**
 * Parameter object for {@see ChatNotificationInterface::sendPriceUpdateAlert()}.
 *
 * Groups the product identity, confirmed price changes, and optional enrichment
 * data (title, URL, sale context) into a single transport object.
 */
final readonly class PriceUpdateAlertDataDTO
{
    /**
     * @param list<SkuPriceChange> $priceChanges Confirmed price changes per SKU
     */
    public function __construct(
        public IntId $productId,
        public array $priceChanges,
        public ?string $productTitle = null,
        public ?string $productUrl = null,
        public ?SaleSettings $saleSettings = null,
        public ?SaleSubmissionContext $saleSubmissionContext = null,
    ) {}
}
