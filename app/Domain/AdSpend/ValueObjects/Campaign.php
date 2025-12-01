<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class Campaign
{
    /**
     * Ad campaign metadata.
     *
     * @param int    $id     Campaign ID
     * @param string $name   Human-readable campaign name
     * @param string $status Campaign status (ENABLED, PAUSED, REMOVED, UNKNOWN, UNSPECIFIED)
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
    ) {
        Assert::greaterThan($id, 0, 'Campaign ID must be positive');
        Assert::notEmpty($name, 'Campaign name cannot be empty');
        Assert::inArray($status, ['UNSPECIFIED', 'UNKNOWN', 'ENABLED', 'PAUSED', 'REMOVED'], 'Campaign status must be UNSPECIFIED, UNKNOWN, ENABLED, PAUSED, or REMOVED');
    }
}
