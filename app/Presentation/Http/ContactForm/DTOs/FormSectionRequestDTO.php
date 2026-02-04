<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\DTOs;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Core form fields from contact form submission.
 *
 * Handles enum validation via rules() since ContactReason uses labels
 * and CustomerType accepts 'prefer_not_to_say' as a special value.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class FormSectionRequestDTO extends Data
{
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,
        #[Required, StringType, Max(255)]
        public readonly string $email,
        #[Required, StringType]
        public readonly string $reason,
        #[Required, StringType, Max(10000)]
        public readonly string $message,
        #[Nullable, StringType, Max(50)]
        public readonly ?string $phone = null,
        #[Nullable, StringType]
        public readonly ?string $customerType = null,
        #[Nullable, StringType, Max(20)]
        public readonly ?string $orderNumber = null,
        #[Nullable, StringType, Max(20)]
        public readonly ?string $deliveryPostcode = null,
        #[Nullable, Min(1), Max(999)]
        public readonly ?int $quantity = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(self::getReasonLabels())],
            'customer_type' => ['nullable', 'string', Rule::in(self::getCustomerTypeValues())],
        ];
    }

    /**
     * @return list<string>
     */
    private static function getReasonLabels(): array
    {
        return \array_map(
            static fn(ContactReason $reason): string => $reason->label(),
            ContactReason::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private static function getCustomerTypeValues(): array
    {
        $types = \array_map(
            static fn(CustomerType $type): string => $type->value,
            CustomerType::cases(),
        );

        // Frontend sends 'prefer_not_to_say' which maps to null in domain
        $types[] = 'prefer_not_to_say';

        return $types;
    }
}
