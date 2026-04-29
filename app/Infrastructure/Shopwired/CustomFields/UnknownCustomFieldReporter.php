<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\CustomFields;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use Illuminate\Support\Facades\Log;

/**
 * Per-request aggregator for unknown custom field names encountered on the
 * read path. Exists so the multiple CustomFieldFactory instances active in a
 * single request (Product mapper, Product view assembler, Category, Brand)
 * collapse into one summary log line instead of one per factory.
 *
 * **Lifecycle**: MUST be bound `scoped` — counts persist per request/job and
 * are flushed via app terminating callback. A singleton binding would
 * accumulate counts across requests under Octane and silently lie.
 */
final class UnknownCustomFieldReporter
{
    /** @var array<value-of<CustomFieldItemType>, array<string, int>> */
    private array $countsByItemType = [];

    private bool $registered = false;

    public function record(CustomFieldItemType $itemType, string $fieldName): void
    {
        $key = $itemType->value;
        $this->countsByItemType[$key][$fieldName] = ($this->countsByItemType[$key][$fieldName] ?? 0) + 1;

        $this->registerFlushOnce();
    }

    private function registerFlushOnce(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        \app()->terminating(function (): void {
            $this->flush();
        });
    }

    private function flush(): void
    {
        if ($this->countsByItemType === []) {
            return;
        }

        Log::warning('Unknown custom fields encountered - definitions out of sync with ShopWired', [
            'by_item_type' => $this->countsByItemType,
        ]);
    }
}
