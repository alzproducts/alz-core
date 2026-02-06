<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification sent when variant SKUs are generated.
 *
 * Displays product context, creation stats, and up to 5 created SKUs.
 */
final class VariantSkusGeneratedNotification extends Notification
{
    private const int MAX_SKUS_SHOWN = 5;

    /**
     * @param int $productId ShopWired product ID
     * @param string $productTitle Product title
     * @param int $created SKUs created
     * @param int $skipped Variations skipped
     * @param int $failed Variations failed
     * @param list<string> $createdSkus Created SKU values
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $productTitle,
        public readonly int $created,
        public readonly int $skipped,
        public readonly int $failed,
        public readonly array $createdSkus,
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
        $message = (new SlackMessage())
            ->text("Variant SKUs generated for {$this->productTitle}")
            ->headerBlock('Variant SKUs Generated')
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("*{$this->productTitle}* (ID: {$this->productId})")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $stats = "Created: *{$this->created}* | Skipped: {$this->skipped} | Failed: {$this->failed}";
                $block->text($stats)->markdown();
            })
            ->dividerBlock();

        if ($this->createdSkus !== []) {
            $message->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->buildSkuList())->markdown();
            });
        }

        return $message->contextBlock(static function (ContextBlock $block): void {
            $block->text('Generated at ' . \now()->format('Y-m-d H:i:s'));
        });
    }

    private function buildSkuList(): string
    {
        $visible = \array_slice($this->createdSkus, 0, self::MAX_SKUS_SHOWN);
        $lines = \array_map(static fn(string $sku): string => "`{$sku}`", $visible);
        $text = \implode("\n", $lines);

        $remaining = \count($this->createdSkus) - self::MAX_SKUS_SHOWN;
        if ($remaining > 0) {
            $text .= "\n+ {$remaining} more";
        }

        return $text;
    }
}
