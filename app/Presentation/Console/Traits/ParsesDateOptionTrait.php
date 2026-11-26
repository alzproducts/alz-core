<?php

declare(strict_types=1);

namespace App\Presentation\Console\Traits;

use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * @phpstan-require-extends Command
 */
trait ParsesDateOptionTrait
{
    /** Null unless the option is a real YYYY-MM-DD date. */
    private function date(string $option): ?DateTimeImmutable
    {
        $value = $this->option($option);
        if (!\is_string($value) || \preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];

        return \checkdate($month, $day, $year)
            ? new DateTimeImmutable('@0')->setDate($year, $month, $day)
            : null;
    }
}
