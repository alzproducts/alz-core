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
                    'Extract logical sections into well-named private methods, each with a single responsibility. Do not split arbitrarily at a line count — each extracted method should represent a coherent operation.',
                ],
            ],
        );
    }

    public function testNoErrorOnRepositoryMethodUnderThirtyLines(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveMethodLengthRule/ValidRepository.php'],
            [],
        );
    }

    public function testErrorOnRepositoryMethodExceedingThirtyLines(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveMethodLengthRule/InvalidRepository.php'],
            [
                [
                    'Method tooLongRepositoryMethod() is 33 lines long — exceeds the 30-line limit. Break it into smaller, focused methods.',
                    9,
                    'Extract logical sections into well-named private methods, each with a single responsibility. Do not split arbitrarily at a line count — each extracted method should represent a coherent operation.',
                ],
            ],
        );
    }

    public function testNoErrorOnClientMethodUnderThirtyLines(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveMethodLengthRule/ValidClient.php'],
            [],
        );
    }

    public function testErrorOnClientMethodExceedingThirtyLines(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ExcessiveMethodLengthRule/InvalidClient.php'],
            [
                [
                    'Method tooLongClientMethod() is 33 lines long — exceeds the 30-line limit. Break it into smaller, focused methods.',
                    9,
                    'Extract logical sections into well-named private methods, each with a single responsibility. Do not split arbitrarily at a line count — each extracted method should represent a coherent operation.',
                ],
            ],
        );
    }
}
