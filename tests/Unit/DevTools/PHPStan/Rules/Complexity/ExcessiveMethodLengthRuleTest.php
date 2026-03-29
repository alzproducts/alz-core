<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\PHPStan\Rules\Complexity;

use App\DevTools\PHPStan\Rules\Complexity\ExcessiveMethodLengthRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ExcessiveMethodLengthRule>
 */
final class ExcessiveMethodLengthRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ExcessiveMethodLengthRule();
    }

    public function testNoErrorOnShortMethod(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveMethodLengthRule/ValidClass.php'],
            [],
        );
    }

    public function testErrorOnMethodExceedingTwentyLines(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveMethodLengthRule/InvalidClass.php'],
            [
                [
                    'Method tooLongMethod() is 23 lines long — exceeds the 20-line limit. Break it into smaller, focused methods.',
                    9,
                ],
            ],
        );
    }
}
