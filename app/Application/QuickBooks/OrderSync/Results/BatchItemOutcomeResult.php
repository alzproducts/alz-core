<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use Webmozart\Assert\Assert;

/**
 * One document's outcome from a batch create.
 */
final readonly class BatchItemOutcomeResult
{
    /**
     * @param non-empty-string|null $qboId
     */
    private function __construct(
        public BatchOutcomeKind $kind,
        public ?string $qboId,
        public ?QuickBooksFault $fault,
        public ?int $retryAfterSeconds,
    ) {}

    public static function created(string $qboId): self
    {
        Assert::stringNotEmpty($qboId, 'A created document carries its QBO id');

        return new self(BatchOutcomeKind::Created, $qboId, null, null);
    }

    public static function faulted(QuickBooksFault $fault): self
    {
        return new self(BatchOutcomeKind::Faulted, null, $fault, null);
    }

    public static function rateLimited(?int $retryAfterSeconds): self
    {
        return new self(BatchOutcomeKind::RateLimited, null, null, $retryAfterSeconds);
    }

    public static function ambiguous(): self
    {
        return new self(BatchOutcomeKind::Ambiguous, null, null, null);
    }
}
