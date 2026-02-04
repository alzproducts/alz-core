<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Mappers;

use App\Application\HelpScout\Requests\CreateCustomerRequestDTO;
use App\Infrastructure\HelpScout\Services\NameFormatterService;
use App\Infrastructure\HelpScout\Services\PhoneFormatterService;
use HelpScout\Api\Customers\Customer;

/**
 * Maps application requests to HelpScout SDK Customer objects.
 *
 * Handles name parsing (splitting full name into first/last) and
 * phone formatting (UK national format, international with country code).
 *
 * HelpScout auto-creates customers by email if they don't exist,
 * so no "find or create" logic is needed.
 */
final readonly class CustomerMapper
{
    public function __construct(
        private NameFormatterService $nameFormatter,
        private PhoneFormatterService $phoneFormatter,
    ) {}

    /**
     * Convert a CreateCustomerRequestDTO to a HelpScout SDK Customer.
     */
    public function toSdk(CreateCustomerRequestDTO $request): Customer
    {
        $customer = new Customer();

        if ($request->email !== null && $request->email !== '') {
            $customer->addEmail($request->email);
        }

        if ($request->name !== null && $request->name !== '') {
            $parsed = $this->nameFormatter->parse($request->name);
            $customer->setFirstName($parsed['firstName']);

            if ($parsed['lastName'] !== null) {
                $customer->setLastName($parsed['lastName']);
            }
        }

        if ($request->phone !== null && $request->phone !== '') {
            $formattedPhone = $this->phoneFormatter->format($request->phone);
            $customer->addPhone($formattedPhone);
        }

        return $customer;
    }
}
