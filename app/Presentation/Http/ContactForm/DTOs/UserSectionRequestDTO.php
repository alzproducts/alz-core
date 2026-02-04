<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * User identification from contact form submission.
 *
 * Optional section - entire user object can be omitted.
 * Contains ShopWired customer ID when user is logged in.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class UserSectionRequestDTO extends Data
{
    public function __construct(
        #[Nullable, StringType, Max(50)]
        public readonly ?string $customerId = null,
    ) {}
}
