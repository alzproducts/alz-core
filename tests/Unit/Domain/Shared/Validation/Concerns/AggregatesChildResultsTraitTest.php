<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Validation\Concerns;

use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Validation\Concerns\AggregatesChildResultsTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Trait tested via anonymous classes — no #[CoversClass] (PHPUnit doesn't support traits). */
final class AggregatesChildResultsTraitTest extends TestCase
{
    #[Test]
    public function all_children_pass_means_aggregate_passes(): void
    {
        $aggregate = $this->createAggregate([
            'check_a' => $this->createChildResult(passed: true),
            'check_b' => $this->createChildResult(passed: true),
        ]);

        self::assertTrue($aggregate->passed());
        self::assertFalse($aggregate->failed());
    }

    #[Test]
    public function any_child_failure_means_aggregate_fails(): void
    {
        $aggregate = $this->createAggregate([
            'check_a' => $this->createChildResult(passed: true),
            'check_b' => $this->createChildResult(passed: false, reason: 'B failed', context: ['key' => 'val']),
        ]);

        self::assertFalse($aggregate->passed());
        self::assertTrue($aggregate->failed());
    }

    #[Test]
    public function reason_joins_failed_child_reasons_with_semicolons(): void
    {
        $aggregate = $this->createAggregate([
            'sku' => $this->createChildResult(passed: false, reason: 'SKU missing'),
            'price' => $this->createChildResult(passed: true),
            'stock' => $this->createChildResult(passed: false, reason: 'Stock insufficient'),
        ]);

        self::assertSame('SKU missing; Stock insufficient', $aggregate->reason());
    }

    #[Test]
    public function single_failure_reason_has_no_semicolons(): void
    {
        $aggregate = $this->createAggregate([
            'only_check' => $this->createChildResult(passed: false, reason: 'Single failure'),
        ]);

        self::assertSame('Single failure', $aggregate->reason());
    }

    #[Test]
    public function context_nests_failed_child_contexts_under_keys(): void
    {
        $aggregate = $this->createAggregate([
            'sku_validation' => $this->createChildResult(
                passed: false,
                reason: 'SKU missing',
                context: ['missing_skus' => ['SKU-A']],
            ),
            'price_validation' => $this->createChildResult(passed: true),
            'stock_validation' => $this->createChildResult(
                passed: false,
                reason: 'Stock low',
                context: ['available' => 0],
            ),
        ]);

        self::assertSame([
            'sku_validation' => ['missing_skus' => ['SKU-A']],
            'stock_validation' => ['available' => 0],
        ], $aggregate->context());
    }

    #[Test]
    public function or_fail_throws_with_aggregated_data(): void
    {
        $aggregate = $this->createAggregate([
            'check_a' => $this->createChildResult(
                passed: false,
                reason: 'A failed',
                context: ['a_detail' => 1],
            ),
            'check_b' => $this->createChildResult(
                passed: false,
                reason: 'B failed',
                context: ['b_detail' => 2],
            ),
        ]);

        try {
            $aggregate->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertSame('A failed; B failed', $e->reason());
            self::assertSame([
                'check_a' => ['a_detail' => 1],
                'check_b' => ['b_detail' => 2],
            ], $e->context());
        }
    }

    #[Test]
    public function or_fail_is_noop_when_all_pass(): void
    {
        $aggregate = $this->createAggregate([
            'check_a' => $this->createChildResult(passed: true),
        ]);

        // Should not throw
        $aggregate->orFail();

        self::assertTrue($aggregate->passed());
    }

    /**
     * @param  array<string, DescribableValidationResultInterface>  $children
     */
    private function createAggregate(array $children): object
    {
        return new class ($children) implements DescribableValidationResultInterface {
            use AggregatesChildResultsTrait;

            /**
             * @param  array<string, DescribableValidationResultInterface>  $children
             */
            public function __construct(
                private readonly array $children,
            ) {}

            /** @return array<string, DescribableValidationResultInterface> */
            protected function childResults(): array
            {
                return $this->children;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createChildResult(
        bool $passed,
        string $reason = '',
        array $context = [],
    ): DescribableValidationResultInterface {
        return new class ($passed, $reason, $context) implements DescribableValidationResultInterface {
            /**
             * @param  array<string, mixed>  $context
             */
            public function __construct(
                private readonly bool $hasPassed,
                private readonly string $failReason,
                private readonly array $failContext,
            ) {}

            public function passed(): bool
            {
                return $this->hasPassed;
            }

            public function failed(): bool
            {
                return ! $this->hasPassed;
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

            public function orFail(): void
            {
                // Not needed for child stubs
            }
        };
    }
}
