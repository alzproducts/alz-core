<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity\Fixtures\ExcessiveClassLengthRule;

final class ValidClass
{
    public function methodOne(): void
    {
        $a = 1;
    }

    public function methodTwo(): void
    {
        $b = 2;
    }

    public function methodThree(): void
    {
        $c = 3;
    }
}
