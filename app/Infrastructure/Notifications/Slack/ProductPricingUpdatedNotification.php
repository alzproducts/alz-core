<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\SaleSubmissionContext;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification sent when product prices are updated via batch API.
 *
 * Displays product title (with link) and per-SKU price changes showing previous → new effective prices.
 * When sale settings are present (add-to-sale), enriches with reason/discount/end-date/stock.
 * When a removal context is present, enriches with removal reason and original sale context.
 */
final class ProductPricingUpdatedNotification extends Notification
{
    private const int MAX_SKUS_SHOWN = 8;

    /**
     * @param int $productId ShopWired product ID
     * @param list<SkuPriceChange> $priceChanges Confirmed price changes per SKU
     * @param string|null $productTitle Product title for display
     * @param string|null $productUrl Product page URL for linking
     * @param SaleSettings|null $saleSettings Sale context for add-to-sale enrichment
     * @param SaleSubmissionContext|null $saleSubmissionContext Removal snapshot for removal enrichment
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $priceChanges,
        private readonly ?string $productTitle = null,
        private readonly ?string $productUrl = null,
        private readonly ?SaleSettings $saleSettings = null,
        private readonly ?SaleSubmissionContext $saleSubmissionContext = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $count = \count($this->priceChanges);
        $displayName = $this->productTitle ?? "Product {$this->productId}";

        $message = (new SlackMessage())
            ->text("{$displayName}: {$count} price(s) updated")
            ->headerBlock('Product Prices Updated')
            ->sectionBlock(function (SectionBlock $block) use ($displayName): void {
                $block->text("*{$displayName}* | *Updated:* " . \count($this->priceChanges) . ' SKU(s)')->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->buildPriceChangeList())->markdown();
            });

        // Add sale context section when present
        $saleContext = $this->buildSaleContext();
        if ($saleContext !== null) {
            $message->sectionBlock(static function (SectionBlock $block) use ($saleContext): void {
                $block->text($saleContext)->markdown();
            });
        }

        if ($this->productUrl !== null) {
            $message->actionsBlock(function (ActionsBlock $block): void {
                $block->button('View Product')
                    ->url($this->productUrl)
                    ->primary();
            });
        }

        return $message->contextBlock(static function (ContextBlock $block): void {
            $block->text('Updated at ' . \now()->format('Y-m-d H:i:s'));
        });
    }

    private function buildPriceChangeList(): string
    {
        $visible = \array_slice($this->priceChanges, 0, self::MAX_SKUS_SHOWN);
        $lines = \array_map(static function (SkuPriceChange $change): string {
            $previous = \number_format($change->previousPrices->effectivePrice()->toGross(), 2);
            $new = \number_format($change->newPrices->effectivePrice()->toGross(), 2);

            $label = match (true) {
                $change->addedToSale() => ' [SALE]',
                $change->removedFromSale() => ' [SALE ENDED]',
                $change->saleChanged() => ' [SALE]',
                default => '',
            };

            return "`{$change->sku->value}`: £{$previous} → £{$new}{$label}";
        }, $visible);

        $text = \implode("\n", $lines);

        $remaining = \count($this->priceChanges) - self::MAX_SKUS_SHOWN;
        if ($remaining > 0) {
            $text .= "\n+ {$remaining} more";
        }

        return $text;
    }

    private function buildSaleContext(): ?string
    {
        // Removal context takes precedence — event carries snapshot from before DB deletion
        if ($this->saleSubmissionContext !== null) {
            return $this->buildRemovalContext($this->saleSubmissionContext);
        }

        // Add-to-sale context — settings read fresh from DB by the listener
        if ($this->saleSettings !== null) {
            return $this->buildAddToSaleContext($this->saleSettings);
        }

        return null;
    }

    private function buildRemovalContext(SaleSubmissionContext $context): string
    {
        $lines = ["*Removal reason:* {$context->removalReason->label()}"];

        if ($context->saleReason !== null && $context->saleReason !== '') {
            $lines[] = "*Sale reason:* {$context->saleReason}";
        }

        if ($context->saleEndDate !== null) {
            $lines[] = "*Sale ended:* {$context->saleEndDate->format('Y-m-d')}";
        }

        if ($context->saleEndsStock !== null) {
            $lines[] = "*Stock threshold was:* {$context->saleEndsStock} units";
        }

        return \implode("\n", $lines);
    }

    private function buildAddToSaleContext(SaleSettings $settings): ?string
    {
        $lines = [];

        if ($settings->saleReason !== '') {
            $lines[] = "*Sale reason:* {$settings->saleReason}";
        }

        $discountPct = $this->calculateDiscountPercentage();
        if ($discountPct !== null) {
            $lines[] = "*Discount:* {$discountPct}%";
        }

        if ($settings->saleEndDate !== null) {
            $lines[] = "*Sale ends:* {$settings->saleEndDate->format('Y-m-d')}";
        }

        if ($settings->saleEndsStock !== null) {
            $lines[] = "*Stock threshold:* {$settings->saleEndsStock} units";
        }

        return $lines !== [] ? \implode("\n", $lines) : null;
    }

    private function calculateDiscountPercentage(): ?string
    {
        foreach ($this->priceChanges as $change) {
            if ($change->addedToSale()) {
                $base = $change->newPrices->basePrice->toGross();
                $sale = $change->newPrices->effectivePrice()->toGross();
                if ($base > 0 && $sale < $base) {
                    return \number_format((($base - $sale) / $base) * 100, 0);
                }
            }
        }

        return null;
    }
}
