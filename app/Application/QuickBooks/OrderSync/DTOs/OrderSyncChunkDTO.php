<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use Webmozart\Assert\Assert;

/**
 * The references one chunk syncs, and why. It holds no access token, so it may be queued.
 */
final readonly class OrderSyncChunkDTO
{
    public const int MAX_REFERENCES = 20;

    /**
     * @param non-empty-list<int> $references distinct
     */
    public function __construct(
        public string $realmId,
        public array $references,
        public bool $forceRetry,
        public OrderSyncTrigger $trigger,
        public ?string $runId,
    ) {
        Assert::maxCount($references, self::MAX_REFERENCES, 'A chunk holds at most one batch of references');
    }
}
