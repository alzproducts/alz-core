<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\OrderSync\DTOs\DryRunOrderDTO;
use App\Application\QuickBooks\OrderSync\Results\DryRunTotalsResult;
use App\Application\QuickBooks\OrderSync\UseCases\ReportDryRunTotalsUseCase;
use App\Domain\Exceptions\DomainException;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * Stdout carries only the CSV; everything else goes to stderr.
 */
final class QuickBooksDryRunTotalsCommand extends Command
{
    private const string HEADER = 'reference,order_date,expected_total,shopwired_total,difference,result';

    protected $signature = 'quickbooks:dry-run-totals
                            {--from= : First Europe/London order date (YYYY-MM-DD)}
                            {--to= : Last Europe/London order date (YYYY-MM-DD)}';

    protected $description = 'Report each order\'s expected QuickBooks total against its ShopWired total as CSV, posting nothing';

    public function handle(ReportDryRunTotalsUseCase $report): int
    {
        $from = $this->date('from');
        $to = $this->date('to');
        if ($from === null || $to === null) {
            return $this->fails('A report date is missing or invalid', '--from and --to are each a real YYYY-MM-DD date');
        }
        if ($to < $from) {
            return $this->fails('The range ends before it starts', '--to is not before --from');
        }

        try {
            $this->print($report->execute($from, $to));
        } catch (DomainException $e) {
            return $this->fails('Stopped: ' . \class_basename($e), 'the log, then run the command again');
        }

        return self::SUCCESS;
    }

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

    private function print(DryRunTotalsResult $report): void
    {
        $this->line(self::HEADER);
        foreach ($report->orders as $order) {
            $this->line(self::row($order));
        }

        foreach ($report->counts() as $name => $count) {
            $this->output->getErrorStyle()->writeln("{$name}: {$count}");
        }
    }

    private static function row(DryRunOrderDTO $order): string
    {
        return \implode(',', [
            $order->reference,
            $order->orderDate->format('Y-m-d'),
            $order->expectedTotal?->toString() ?? '',
            $order->shopwiredTotal->toString(),
            $order->difference?->toString() ?? '',
            $order->result->value,
        ]);
    }

    private function fails(string $error, string $check): int
    {
        $this->output->getErrorStyle()->writeln(["<error>{$error}</error>", "  Check: {$check}"]);

        return self::FAILURE;
    }
}
