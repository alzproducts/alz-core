<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity\Fixtures\ExcessiveMethodLengthRule;

final class ValidClass
{
    public function shortMethod(): void
    {
        $a = 1;
        $b = 2;
        $c = $a + $b;

        echo $c;
    }

    public function exactlyTwentyLines(): void
    {
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        $f = 6;
        $g = 7;
        $h = 8;
        $i = 9;
        $j = 10;
        $k = 11;
        $l = 12;
        $m = 13;
        $n = 14;
        $o = 15;
        $p = 16;
        $q = 17;
        $r = 18;
    }
}
