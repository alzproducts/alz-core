<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\Validators;

use App\Application\Catalog\Validators\CustomFieldSubmissionValidator;
use App\Application\Contracts\Shopwired\CustomFieldValueFactoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\CustomFieldNotFoundException;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CustomFieldSubmissionValidator::class)]
final class CustomFieldSubmissionValidatorTest extends TestCase
{
    private CustomFieldValueFactoryInterface&MockInterface $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = Mockery::mock(CustomFieldValueFactoryInterface::class);
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function returns_valid_result_when_factory_succeeds(): void
    {
        $rawFields = ['colour' => 'Red', 'notes' => 'Some text'];

        $this->factory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with($rawFields)
            ->andReturn([]);

        $validator = new CustomFieldSubmissionValidator($this->factory, $rawFields);
        $result = $validator->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame('', $result->reason());
    }

    // ========================================================================
    // Unknown Field
    // ========================================================================

    #[Test]
    public function returns_unknown_field_result_when_field_not_found(): void
    {
        $rawFields = ['nonexistent_field' => 'value'];

        $this->factory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with($rawFields)
            ->andThrow(new CustomFieldNotFoundException('nonexistent_field', CustomFieldItemType::Product));

        $validator = new CustomFieldSubmissionValidator($this->factory, $rawFields);
        $result = $validator->validate();

        self::assertFalse($result->passed());
        self::assertTrue($result->failed());
        self::assertSame(
            "Unknown custom field 'nonexistent_field' for item type 'product'",
            $result->reason(),
        );
    }

    // ========================================================================
    // Invalid Value
    // ========================================================================

    #[Test]
    public function returns_invalid_value_result_when_type_mismatches(): void
    {
        $rawFields = ['release_date' => 'not-a-timestamp'];

        $this->factory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with($rawFields)
            ->andThrow(new InvalidCustomFieldValueException(
                fieldName: 'release_date',
                expectedType: CustomFieldType::Date,
                actualType: 'string',
                rawValue: 'not-a-timestamp',
            ));

        $validator = new CustomFieldSubmissionValidator($this->factory, $rawFields);
        $result = $validator->validate();

        self::assertFalse($result->passed());
        self::assertTrue($result->failed());
        self::assertSame(
            "Custom field 'release_date' expected type 'date' but received 'string'",
            $result->reason(),
        );
    }
}
