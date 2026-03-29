<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity\Fixtures\ExcessiveParameterCountRule;

final class InvalidClass
{
    public function tooManyParams(int $a, int $b, int $c, int $d, int $e): int
    {
        return $a + $b + $c + $d + $e;
    }
}
