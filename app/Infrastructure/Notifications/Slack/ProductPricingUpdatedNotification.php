<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification sent when product prices are updated via batch API.
 *
 * Displays product ID with per-SKU price changes showing previous → new effective prices.
 */
final class ProductPricingUpdatedNotification extends Notification
{
    private const int MAX_SKUS_SHOWN = 8;

    /**
     * @param int $productId ShopWired product ID
     * @param list<SkuPriceChange> $priceChanges Confirmed price changes per SKU
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $priceChanges,
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

        return (new SlackMessage())
            ->text("Product {$this->productId}: {$count} price(s) updated")
            ->headerBlock('Product Prices Updated')
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("*Product ID:* {$this->productId} | *Updated:* " . \count($this->priceChanges) . ' SKU(s)')->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->buildPriceChangeList())->markdown();
            })
            ->contextBlock(static function (ContextBlock $block): void {
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
}
