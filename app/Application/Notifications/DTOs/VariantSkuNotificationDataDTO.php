<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTOs;

use App\Application\Contracts\ChatNotificationInterface;

/**
 * Parameter object for {@see ChatNotificationInterface::sendVariantSkusGenerated()}.
 *
 * Groups the variant SKU generation result fields into a single transport object.
 */
final readonly class VariantSkuNotificationDataDTO
{
    /**
     * @param list<string> $createdVariants Created variant labels
     */
    public function __construct(
        public int $productId,
        public string $productTitle,
        public int $created,
        public int $skipped,
        public int $failed,
        public array $createdVariants,
    ) {}
}
