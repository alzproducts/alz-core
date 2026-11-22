<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\ValueObjects;

use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use Webmozart\Assert\Assert;

/**
 * A Fault QuickBooks returned. `message` is Intuit's `Message - Detail` text and can hold customer data,
 * so it must stay out of logs and exception context.
 */
final readonly class QuickBooksFault
{
    public function __construct(
        public QuickBooksFaultKind $kind,
        public ?string $code = null,
        public ?string $element = null,
        public ?string $message = null,
    ) {
        Assert::nullOrStringNotEmpty($code);
        Assert::nullOrStringNotEmpty($element);
        Assert::nullOrStringNotEmpty($message);
    }
}
