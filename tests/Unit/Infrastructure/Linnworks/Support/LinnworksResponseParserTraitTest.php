<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Support;

use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Data;
use Tests\TestCase;

/**
 * LinnworksResponseParserTrait Unit Tests.
 *
 * Tests response parsing utilities:
 * - parseWrappedArray() for {Items: [...]} responses
 * - parseSingleToDomain() for single object responses
 * - Error handling for invalid response structures
 * - Critical logging on parsing failures
 */
#[CoversTrait(LinnworksResponseParserTrait::class)]
final class LinnworksResponseParserTraitTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | parseWrappedArray Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_wrapped_array_response_with_items_key(): void
    {
        $data = [
            'Items' => [
                ['id' => '1', 'name' => 'Item 1'],
                ['id' => '2', 'name' => 'Item 2'],
            ],
        ];

        $result = TestableParserStub::testParseWrappedArray($data, TestSimpleDTO::class);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(TestSimpleDTO::class, $result[0]);
        $this->assertSame('1', $result[0]->id);
        $this->assertSame('Item 1', $result[0]->name);
        $this->assertSame('2', $result[1]->id);
        $this->assertSame('Item 2', $result[1]->name);
    }

    #[Test]
    public function it_parses_wrapped_array_with_custom_key(): void
    {
        $data = [
            'Results' => [
                ['id' => '1', 'name' => 'Custom Key Item'],
            ],
        ];

        $result = TestableParserStub::testParseWrappedArray($data, TestSimpleDTO::class, 'Results');

        $this->assertCount(1, $result);
        $this->assertSame('Custom Key Item', $result[0]->name);
    }

    #[Test]
    public function it_returns_empty_array_when_items_key_is_empty(): void
    {
        $data = ['Items' => []];

        $result = TestableParserStub::testParseWrappedArray($data, TestSimpleDTO::class);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_array_when_custom_key_is_missing(): void
    {
        $data = ['SomeOtherKey' => []];

        $result = TestableParserStub::testParseWrappedArray($data, TestSimpleDTO::class, 'Items');

        $this->assertSame([], $result);
    }

    #[Test]
    #[DataProvider('invalidWrappedArrayDataProvider')]
    public function it_throws_exception_for_invalid_wrapped_array_data(
        mixed $data,
        string $expectedMessagePart,
    ): void {
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'API response validation failed')
                    && \str_contains($context['error'], $expectedMessagePart));

        $this->expectException(InvalidApiResponseException::class);

        TestableParserStub::testParseWrappedArray($data, TestSimpleDTO::class);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidWrappedArrayDataProvider(): array
    {
        return [
            'null data' => [null, "Expected wrapped response with 'Items' key"],
            'string data' => ['not an array', "Expected wrapped response with 'Items' key"],
            'integer data' => [42, "Expected wrapped response with 'Items' key"],
            'items key is string' => [['Items' => 'not-array'], "Expected 'Items' to be an array"],
            'items key is integer' => [['Items' => 123], "Expected 'Items' to be an array"],
        ];
    }

    #[Test]
    public function it_throws_exception_when_dto_validation_fails_on_wrapped_array(): void
    {
        $data = [
            'Items' => [
                ['invalid_field' => 'value'], // Missing required 'id' and 'name'
            ],
        ];

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message): bool => \str_contains($message, 'API response validation failed'));

        try {
            TestableParserStub::testParseWrappedArray($data, TestSimpleDTO::class);
            $this->fail('Expected InvalidApiResponseException');
        } catch (InvalidApiResponseException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertStringContainsString('API returned invalid data structure', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | parseSingleToDomain Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_single_object_and_converts_to_domain(): void
    {
        $data = ['id' => 'domain-123', 'name' => 'Domain Item'];

        $result = TestableParserStub::testParseSingleToDomain($data, TestDomainConvertibleDTO::class);

        $this->assertInstanceOf(TestDomainObject::class, $result);
        $this->assertSame('domain-123', $result->domainId);
        $this->assertSame('Domain Item', $result->domainName);
    }

    #[Test]
    #[DataProvider('invalidSingleObjectDataProvider')]
    public function it_throws_exception_for_invalid_single_object_data(mixed $data): void
    {
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'API response validation failed')
                && $context['error'] === 'Expected object response');

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Expected object response');

        TestableParserStub::testParseSingleToDomain($data, TestDomainConvertibleDTO::class);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidSingleObjectDataProvider(): array
    {
        return [
            'null data' => [null],
            'string data' => ['not an object'],
            'integer data' => [42],
        ];
    }

    #[Test]
    public function it_throws_exception_when_dto_validation_fails_on_single_object(): void
    {
        $data = ['wrong_field' => 'value']; // Missing required 'id' and 'name'

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message): bool => \str_contains($message, 'API response validation failed'));

        try {
            TestableParserStub::testParseSingleToDomain($data, TestDomainConvertibleDTO::class);
            $this->fail('Expected InvalidApiResponseException');
        } catch (InvalidApiResponseException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertStringContainsString('API returned invalid data structure', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_raw_response_on_parsing_failure(): void
    {
        $rawData = ['unexpected' => 'structure', 'with' => 'data'];

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Linnworks API response validation failed'
                    && $context['raw_response'] === $rawData);

        $this->expectException(InvalidApiResponseException::class);

        TestableParserStub::testParseSingleToDomain($rawData, TestDomainConvertibleDTO::class);
    }
}

/*
|--------------------------------------------------------------------------
| Test Helper Classes
|--------------------------------------------------------------------------
*/

/**
 * Exposes trait methods for testing.
 */
final class TestableParserStub
{
    use LinnworksResponseParserTrait;

    /**
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return list<T>
     */
    public static function testParseWrappedArray(mixed $data, string $dtoClass, string $key = 'Items'): array
    {
        return self::parseWrappedArray($data, $dtoClass, $key);
    }

    public static function testParseSingleToDomain(mixed $data, string $dtoClass): object
    {
        return self::parseSingleToDomain($data, $dtoClass);
    }
}

/**
 * Simple DTO for testing parseWrappedArray.
 */
final class TestSimpleDTO extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}

/**
 * Domain object for testing parseSingleToDomain.
 */
final class TestDomainObject
{
    public function __construct(
        public readonly string $domainId,
        public readonly string $domainName,
    ) {}
}

/**
 * DTO with toDomain() for testing parseSingleToDomain.
 */
final class TestDomainConvertibleDTO extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    public function toDomain(): TestDomainObject
    {
        return new TestDomainObject(
            domainId: $this->id,
            domainName: $this->name,
        );
    }
}
