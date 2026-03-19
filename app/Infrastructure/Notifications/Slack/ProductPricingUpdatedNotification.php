<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

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
 */
final class ProductPricingUpdatedNotification extends Notification
{
    private const int MAX_SKUS_SHOWN = 8;

    /**
     * @param int $productId ShopWired product ID
     * @param list<SkuPriceChange> $priceChanges Confirmed price changes per SKU
     * @param string|null $productTitle Product title for display
     * @param string|null $productUrl Product page URL for linking
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $priceChanges,
        private readonly ?string $productTitle = null,
        private readonly ?string $productUrl = null,
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
}
