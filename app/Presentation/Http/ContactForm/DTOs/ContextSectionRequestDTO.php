<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Page and session context from contact form submission.
 *
 * Timestamp is required; other fields are optional.
 * URLs and user agent stored as TEXT columns (no length limit).
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ContextSectionRequestDTO extends Data
{
    public function __construct(
        #[Required, StringType, Date]
        public readonly string $timestamp,
        #[Nullable, StringType]
        public readonly ?string $pageUrl = null,
        #[Nullable, StringType]
        public readonly ?string $referrerUrl = null,
        #[Nullable, StringType]
        public readonly ?string $userAgent = null,
    ) {}
}
