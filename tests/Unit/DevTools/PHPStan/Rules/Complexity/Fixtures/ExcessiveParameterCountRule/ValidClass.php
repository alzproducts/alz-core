<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity\Fixtures\ExcessiveParameterCountRule;

final class ValidClass
{
    public function __construct(
        private readonly string $a,
        private readonly string $b,
        private readonly string $c,
        private readonly string $d,
        private readonly string $e,
        private readonly string $f,
    ) {}

    public function exactlyFourParams(int $a, int $b, int $c, int $d): int
    {
        return $a + $b + $c + $d;
    }

    public function fewerParams(int $a, int $b): int
    {
        return $a + $b;
    }
}
