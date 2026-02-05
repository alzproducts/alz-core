<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Top-level contact form request DTO.
 *
 * Assembles nested section DTOs from the JSON request body.
 * Use ContactFormRequestDTO::from($request) to validate and parse.
 *
 * Optional sections (attribution, product, user) use ?TypeDTO = null
 * to handle when the entire section is omitted from the request.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ContactFormRequestDTO extends Data
{
    public function __construct(
        #[Required]
        public readonly FormSectionRequestDTO $form,
        #[Required]
        public readonly ConsentSectionRequestDTO $consent,
        #[Required]
        public readonly ContextSectionRequestDTO $context,
        public readonly ?AttributionSectionRequestDTO $attribution = null,
        public readonly ?ProductSectionRequestDTO $product = null,
        public readonly ?UserSectionRequestDTO $user = null,
    ) {}
}
