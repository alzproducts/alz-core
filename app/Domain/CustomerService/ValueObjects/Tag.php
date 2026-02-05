<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * A tag for categorizing customer service items.
 *
 * Tags are used to categorize conversations, tickets, or other
 * customer service items. The ID is optional as new tags may
 * not have an external system ID assigned yet.
 */
final readonly class Tag
{
    /**
     * @param string $name Tag name (will be normalized to lowercase)
     * @param int|null $id External system ID (if known)
     */
    public function __construct(
        public string $name,
        public ?int $id = null,
    ) {
        Assert::notEmpty($name, 'Tag name cannot be empty');
    }

    /**
     * Create a tag with normalized (lowercase, trimmed) name.
     */
    public static function fromName(string $name): self
    {
        return new self(\mb_strtolower(\mb_trim($name)));
    }

    /**
     * Create a tag with ID from external system.
     */
    public static function withId(string $name, int $id): self
    {
        return new self(\mb_strtolower(\mb_trim($name)), $id);
    }
}
