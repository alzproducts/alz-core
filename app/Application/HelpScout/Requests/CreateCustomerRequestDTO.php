<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Requests;

use App\Domain\Exceptions\Data\InsufficientDataException;

/**
 * Request DTO for creating a HelpScout customer.
 *
 * At least one contact method (email or phone) must be provided.
 * All fields are optional individually, but email+phone cannot both be empty.
 */
final readonly class CreateCustomerRequestDTO
{
    public ?string $email;
    public ?string $name;
    public ?string $phone;

    /**
     * @throws InsufficientDataException When both email and phone are empty/null
     */
    public function __construct(
        ?string $email = null,
        ?string $name = null,
        ?string $phone = null,
    ) {
        $this->email = $email !== null ? \mb_strtolower(\mb_trim($email)) : null;
        $this->name = $name !== null ? \mb_trim($name) : null;
        $this->phone = $phone !== null ? \mb_trim($phone) : null;

        // Validate at least one contact method exists
        $hasEmail = $this->email !== null && $this->email !== '';
        $hasPhone = $this->phone !== null && $this->phone !== '';

        if (!$hasEmail && !$hasPhone) {
            throw new InsufficientDataException('Customer', 'at least an email or phone number');
        }
    }
}
