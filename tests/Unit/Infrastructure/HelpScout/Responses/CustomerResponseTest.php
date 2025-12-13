<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Infrastructure\HelpScout\Responses\CustomerResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CustomerResponse::class)]
final class CustomerResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_with_all_fields(): void
    {
        $apiResponse = [
            'id' => 12345,
            'type' => 'customer',
            'first' => 'Alice',
            'last' => 'Johnson',
            'email' => 'alice@example.com',
        ];

        $customerResponse = CustomerResponse::from($apiResponse);

        $this->assertSame(12345, $customerResponse->id);
        $this->assertSame('customer', $customerResponse->type);
        $this->assertSame('Alice', $customerResponse->first);
        $this->assertSame('Johnson', $customerResponse->last);
        $this->assertSame('alice@example.com', $customerResponse->email);
    }

    #[Test]
    public function it_parses_api_response_with_nullable_fields(): void
    {
        $apiResponse = [
            'id' => 67890,
            'type' => 'customer',
            'first' => null,
            'last' => null,
            'email' => null,
        ];

        $customerResponse = CustomerResponse::from($apiResponse);

        $this->assertSame(67890, $customerResponse->id);
        $this->assertNull($customerResponse->first);
        $this->assertNull($customerResponse->last);
        $this->assertNull($customerResponse->email);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_conversation_customer(): void
    {
        $apiResponse = [
            'id' => 12345,
            'type' => 'customer',
            'first' => 'Bob',
            'last' => 'Wilson',
            'email' => 'bob@example.com',
        ];

        $customerResponse = CustomerResponse::from($apiResponse);
        $domainCustomer = $customerResponse->toDomain();

        $this->assertInstanceOf(ConversationCustomer::class, $domainCustomer);
        $this->assertSame(12345, $domainCustomer->id);
        $this->assertSame('Bob', $domainCustomer->firstName);
        $this->assertSame('Wilson', $domainCustomer->lastName);
        $this->assertSame('bob@example.com', $domainCustomer->email);
    }

    #[Test]
    public function it_maps_first_and_last_to_firstName_and_lastName_in_domain(): void
    {
        // HelpScout uses 'first' and 'last' for primaryCustomer (different from other user objects)
        $apiResponse = [
            'id' => 99999,
            'type' => 'customer',
            'first' => 'Charlie',
            'last' => 'Brown',
            'email' => 'charlie@example.com',
        ];

        $customerResponse = CustomerResponse::from($apiResponse);
        $domainCustomer = $customerResponse->toDomain();

        $this->assertSame('Charlie', $domainCustomer->firstName);
        $this->assertSame('Brown', $domainCustomer->lastName);
    }

    #[Test]
    public function it_preserves_null_values_in_domain_conversion(): void
    {
        $apiResponse = [
            'id' => 11111,
            'type' => 'customer',
            'first' => null,
            'last' => null,
            'email' => null,
        ];

        $customerResponse = CustomerResponse::from($apiResponse);
        $domainCustomer = $customerResponse->toDomain();

        $this->assertNull($domainCustomer->firstName);
        $this->assertNull($domainCustomer->lastName);
        $this->assertNull($domainCustomer->email);
    }
}
