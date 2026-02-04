<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\Enums;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ActionStatus Enum Unit Tests.
 *
 * Tests the processing status logic for contact submission actions.
 * isTerminal() is critical for determining when to stop retry attempts.
 */
#[CoversClass(ActionStatus::class)]
final class ActionStatusTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | label() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('labelProvider')]
    public function it_returns_correct_label_for_each_case(ActionStatus $status, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $status->label());
    }

    /**
     * @return array<string, array{ActionStatus, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'Pending returns Pending' => [ActionStatus::Pending, 'Pending'],
            'Processing returns Processing' => [ActionStatus::Processing, 'Processing'],
            'Completed returns Completed' => [ActionStatus::Completed, 'Completed'],
            'Failed returns Failed' => [ActionStatus::Failed, 'Failed'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | isTerminal() Tests - CRITICAL BUSINESS LOGIC
    |--------------------------------------------------------------------------
    | Terminal states determine when to stop processing/retrying.
    | Completed and Failed are terminal; Pending and Processing are not.
    */

    #[Test]
    #[DataProvider('terminalProvider')]
    public function it_correctly_identifies_terminal_states(ActionStatus $status, bool $expectedTerminal): void
    {
        self::assertSame($expectedTerminal, $status->isTerminal());
    }

    /**
     * @return array<string, array{ActionStatus, bool}>
     */
    public static function terminalProvider(): array
    {
        return [
            'Pending is NOT terminal' => [ActionStatus::Pending, false],
            'Processing is NOT terminal' => [ActionStatus::Processing, false],
            'Completed IS terminal' => [ActionStatus::Completed, true],
            'Failed IS terminal' => [ActionStatus::Failed, true],
        ];
    }

    #[Test]
    public function exactly_two_states_are_terminal(): void
    {
        $terminalCount = 0;
        foreach (ActionStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $terminalCount++;
            }
        }

        self::assertSame(2, $terminalCount, 'Expected exactly 2 terminal states (Completed, Failed)');
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Structure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_exactly_four_cases(): void
    {
        self::assertCount(4, ActionStatus::cases());
    }

    #[Test]
    public function backing_values_match_database_constraint(): void
    {
        // These values must match the CHECK constraint in customer_service.contact_submission_actions
        self::assertSame('pending', ActionStatus::Pending->value);
        self::assertSame('processing', ActionStatus::Processing->value);
        self::assertSame('completed', ActionStatus::Completed->value);
        self::assertSame('failed', ActionStatus::Failed->value);
    }
}
