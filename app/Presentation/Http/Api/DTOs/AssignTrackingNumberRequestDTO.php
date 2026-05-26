<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class AssignTrackingNumberRequestDTO extends Data
{
    public function __construct(
        #[Required, BooleanType]
        public readonly bool $marketingConsentGranted,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $gclid = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $gclsrc = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $wbraid = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $gbraid = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $msclkid = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $fbclid = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $utmSource = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $utmMedium = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $utmCampaign = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $utmContent = null,
        #[Nullable, StringType, Max(255)]
        public readonly ?string $utmTerm = null,
    ) {}
}
