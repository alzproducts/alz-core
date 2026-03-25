<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\Validators;

use App\Application\Catalog\Validators\CustomFieldSubmissionResult;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Exceptions\ValidationFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CustomFieldSubmissionResult::class)]
final class CustomFieldSubmissionResultTest extends TestCase
{
    // ========================================================================
    // valid()
    // ========================================================================

    #[Test]
    public function valid_result_passes(): void
    {
        $result = CustomFieldSubmissionResult::valid();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }

    // ========================================================================
    // unknownField()
    // ========================================================================

    #[Test]
    public function unknown_field_result_fails(): void
    {
        $result = CustomFieldSubmissionResult::unknownField('colour', CustomFieldItemType::Product);

        self::assertFalse($result->passed());
        self::assertTrue($result->failed());
        self::assertSame("Unknown custom field 'colour' for item type 'product'", $result->reason());
        self::assertSame(
            ['custom_fields' => ["The field 'colour' is not a recognised custom field."]],
            $result->context(),
        );
    }

    // ========================================================================
    // invalidValue()
    // ========================================================================

    #[Test]
    public function invalid_value_result_fails(): void
    {
        $result = CustomFieldSubmissionResult::invalidValue('release_date', CustomFieldType::Date, 'string');

        self::assertFalse($result->passed());
        self::assertTrue($result->failed());
        self::assertSame("Custom field 'release_date' expected type 'date' but received 'string'", $result->reason());
        self::assertSame(
            ['custom_fields' => ["The field 'release_date' must be of type 'date', received 'string'."]],
            $result->context(),
        );
    }

    // ========================================================================
    // orFail()
    // ========================================================================

    #[Test]
    public function or_fail_does_nothing_on_valid_result(): void
    {
        $result = CustomFieldSubmissionResult::valid();

        // No exception thrown
        $result->orFail();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function or_fail_throws_on_unknown_field(): void
    {
        $result = CustomFieldSubmissionResult::unknownField('status', CustomFieldItemType::Product);

        $this->expectException(ValidationFailedException::class);
        $this->expectExceptionMessage("Unknown custom field 'status' for item type 'product'");

        $result->orFail();
    }

    #[Test]
    public function or_fail_throws_on_invalid_value(): void
    {
        $result = CustomFieldSubmissionResult::invalidValue('is_featured', CustomFieldType::Toggle, 'string');

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException');
        } catch (ValidationFailedException $e) {
            self::assertSame("Custom field 'is_featured' expected type 'toggle' but received 'string'", $e->reason);
            self::assertSame(
                ['custom_fields' => ["The field 'is_featured' must be of type 'toggle', received 'string'."]],
                $e->context,
            );
        }
    }
}
