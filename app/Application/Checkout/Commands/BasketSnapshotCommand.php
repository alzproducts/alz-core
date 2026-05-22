<?php

declare(strict_types=1);

namespace App\Application\Checkout\Commands;

use App\Application\Checkout\DTOs\VatReliefDeclarationDTO;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Pre-checkout basket snapshot command.
 *
 * Workaround for ShopWired losing basket_comments fields (nominated delivery
 * day, VAT relief, gift note) on Safari/Apple checkout submissions. Captured
 * immediately before the user clicks "Place Order" so it can be fuzzy-matched
 * to the completed order by IP + basket total.
 *
 * IP and user agent are captured server-side — never trusted from the client.
 */
final readonly class BasketSnapshotCommand
{
    public function __construct(
        public string $ipAddress,
        public string $userAgent,
        public Money $basketTotal,
        public ?string $shippingMethodId = null,
        public ?DateTimeImmutable $deliveryDate = null,
        public ?string $giftNote = null,
        public ?VatReliefDeclarationDTO $vatRelief = null,
    ) {
        Assert::notEmpty($ipAddress, 'IP address is required');
        Assert::notEmpty($userAgent, 'User agent is required');
    }
}
