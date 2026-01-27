<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates bulk free delivery update requests.
 *
 * Expected payload:
 * {
 *   "updates": [
 *     {"identifier": "SKU123", "type": "Standard"},
 *     {"identifier": 5585518, "type": "Express"}
 *   ]
 * }
 */
final class SetFreeDeliveryRequest extends FormRequest
{
    public function authorize(): true
    {
        return true; // Auth handled by Supabase JWT middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'updates' => ['required', 'array', 'min:1', 'max:1000'],
            'updates.*.identifier' => ['required'],
            'updates.*.type' => ['required', 'string', Rule::in($this->getAllowedTypes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'updates.required' => 'The updates array is required',
            'updates.array' => 'The updates must be an array',
            'updates.min' => 'At least one update is required',
            'updates.max' => 'Maximum 1000 updates per request',
            'updates.*.identifier.required' => 'Each update must have an identifier',
            'updates.*.type.required' => 'Each update must have a type',
            'updates.*.type.in' => 'Type must be one of: ' . \implode(', ', $this->getAllowedTypes()),
        ];
    }

    /**
     * Get all allowed type values for validation.
     *
     * @return list<string>
     */
    private function getAllowedTypes(): array
    {
        return \array_map(
            static fn(FreeDeliveryType $type): string => $type->value,
            FreeDeliveryType::cases(),
        );
    }
}
