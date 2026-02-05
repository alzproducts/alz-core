<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Mappers;

use App\Domain\CustomerService\ValueObjects\Tag as DomainTag;
use HelpScout\Api\Tags\Tag as SdkTag;

/**
 * Maps domain Tag value objects to HelpScout SDK Tag entities.
 */
final readonly class TagMapper
{
    /**
     * Transform a domain Tag to a HelpScout SDK Tag.
     */
    public static function toSdk(DomainTag $tag): SdkTag
    {
        $sdkTag = new SdkTag();
        $sdkTag->setName($tag->name);

        if ($tag->id !== null) {
            $sdkTag->setId((string) $tag->id);
        }

        return $sdkTag;
    }

    /**
     * Transform multiple domain Tags to SDK Tags.
     *
     * @param list<DomainTag> $tags
     * @return list<SdkTag>
     */
    public static function toSdkCollection(array $tags): array
    {
        return \array_map(self::toSdk(...), $tags);
    }
}
