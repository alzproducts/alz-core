<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\Enums;

use App\Domain\ContactSubmission\Enums\ActionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ActionType Enum Unit Tests.
 *
 * Tests the action type enum used for contact submission processing.
 * Currently only HelpScout, but extensible for future integrations.
 */
#[CoversClass(ActionType::class)]
final class ActionTypeTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | label() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('labelProvider')]
    public function it_returns_correct_label_for_each_case(ActionType $type, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $type->label());
    }

    /**
     * @return array<string, array{ActionType, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'HelpScout returns HelpScout' => [ActionType::HelpScout, 'HelpScout'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Structure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_expected_case_count(): void
    {
        // Currently only HelpScout; update when new integrations added
        self::assertCount(1, ActionType::cases());
    }

    #[Test]
    public function backing_values_match_database_constraint(): void
    {
        // These values must match the CHECK constraint in customer_service.contact_submission_actions
        self::assertSame('helpscout', ActionType::HelpScout->value);
    }
}
