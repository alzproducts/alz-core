<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Consent status from contact form submission.
 *
 * All four consent fields are required booleans.
 * Uses 'boolean' rule via rules() to handle string booleans ("true", "0").
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ConsentSectionRequestDTO extends Data
{
    public function __construct(
        #[Required, BooleanType]
        public readonly bool $marketing,
        #[Required, BooleanType]
        public readonly bool $statistics,
        #[Required, BooleanType]
        public readonly bool $preferences,
        #[Required, BooleanType]
        public readonly bool $hasResponded,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        // Use Laravel's 'boolean' rule to properly handle string booleans
        return [
            'marketing' => ['required', 'boolean'],
            'statistics' => ['required', 'boolean'],
            'preferences' => ['required', 'boolean'],
            'has_responded' => ['required', 'boolean'],
        ];
    }
}
