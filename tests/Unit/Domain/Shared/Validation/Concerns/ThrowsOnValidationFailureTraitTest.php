<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Validation\Concerns;

use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Trait tested via anonymous classes — no #[CoversClass] (PHPUnit doesn't support traits). */
final class ThrowsOnValidationFailureTraitTest extends TestCase
{
    #[Test]
    public function or_fail_is_noop_when_passed(): void
    {
        $result = $this->createPassingResult();

        // Should not throw
        $result->orFail();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function or_fail_throws_when_failed(): void
    {
        $result = $this->createFailingResult(
            reason: 'Validation failed: 3 items missing',
            context: ['missing' => ['a', 'b', 'c']],
        );

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertSame('Validation failed: 3 items missing', $e->reason());
            self::assertSame(['missing' => ['a', 'b', 'c']], $e->context());
            self::assertSame('Validation failed: 3 items missing', $e->getMessage());
        }
    }

    /**
     * @return object{passed: callable(): bool, failed: callable(): bool, reason: callable(): string, context: callable(): array<string, mixed>, orFail: callable(): void}
     */
    private function createPassingResult(): object
    {
        return new class {
            use ThrowsOnValidationFailureTrait;

            public function passed(): bool
            {
                return true;
            }

            public function failed(): bool
            {
                return false;
            }

            public function reason(): string
            {
                return '';
            }

            /** @return array<string, mixed> */
            public function context(): array
            {
                return [];
            }
        };
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @return object{passed: callable(): bool, failed: callable(): bool, reason: callable(): string, context: callable(): array<string, mixed>, orFail: callable(): void}
     */
    private function createFailingResult(string $reason, array $context): object
    {
        return new class ($reason, $context) {
            use ThrowsOnValidationFailureTrait;

            /**
             * @param  array<string, mixed>  $context
             */
            public function __construct(
                private readonly string $failReason,
                private readonly array $failContext,
            ) {}

            public function passed(): bool
            {
                return false;
            }

            public function failed(): bool
            {
                return true;
            }

            public function reason(): string
            {
                return $this->failReason;
            }

            /** @return array<string, mixed> */
            public function context(): array
            {
                return $this->failContext;
            }
        };
    }
}
