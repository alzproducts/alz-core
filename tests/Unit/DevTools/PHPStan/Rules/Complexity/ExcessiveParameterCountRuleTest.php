<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\PHPStan\Rules\Complexity;

use App\DevTools\PHPStan\Rules\Complexity\ExcessiveParameterCountRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ExcessiveParameterCountRule>
 */
final class ExcessiveParameterCountRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ExcessiveParameterCountRule();
    }

    public function testNoErrorOnFourOrFewerParams(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveParameterCountRule/ValidClass.php'],
            [],
        );
    }

    public function testErrorOnMethodExceedingFourParams(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveParameterCountRule/InvalidClass.php'],
            [
                [
                    'Method tooManyParams() has 5 parameters — exceeds the 4-parameter limit. Consider a parameter object or splitting the method.',
                    9,
                ],
            ],
        );
    }
}
