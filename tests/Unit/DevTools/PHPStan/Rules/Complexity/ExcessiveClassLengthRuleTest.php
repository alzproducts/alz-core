<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\PHPStan\Rules\Complexity;

use App\DevTools\PHPStan\Rules\Complexity\ExcessiveClassLengthRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ExcessiveClassLengthRule>
 */
final class ExcessiveClassLengthRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ExcessiveClassLengthRule();
    }

    public function testNoErrorOnShortClass(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveClassLengthRule/ValidClass.php'],
            [],
        );
    }

    public function testErrorOnClassExceedingTwoHundredFiftyLines(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveClassLengthRule/InvalidClass.php'],
            [
                [
                    'Class InvalidClass is 317 lines long — exceeds the 250-line limit. Consider decomposing into smaller, focused classes.',
                    7,
                    'Check whether this class has multiple responsibilities. Look for groups of methods that operate on distinct subsets of dependencies — these are natural split points.',
                ],
            ],
        );
    }
}
