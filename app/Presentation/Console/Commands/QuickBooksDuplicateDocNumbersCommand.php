<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\OrderSync\DTOs\DuplicateDocNumberDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\Results\DuplicateDocNumbersResult;
use App\Application\QuickBooks\OrderSync\UseCases\AuditDuplicateDocNumbersUseCase;
use App\Domain\Exceptions\DomainException;
use App\Presentation\Console\Traits\ParsesDateOptionTrait;
use Illuminate\Console\Command;

/**
 * Stdout carries only the CSV, in which the type,qbo_id pair repeats once per document; everything else goes to stderr.
 */
final class QuickBooksDuplicateDocNumbersCommand extends Command
{
    use ParsesDateOptionTrait;

    private const string HEADER = 'doc_number,type,qbo_id';

    protected $signature = 'quickbooks:duplicate-doc-numbers
                            {--from= : First Europe/London order date (YYYY-MM-DD)}
                            {--to= : Last Europe/London order date (YYYY-MM-DD)}';

    protected $description = 'Report order document numbers QuickBooks holds on more than one document as CSV, posting nothing';

    public function handle(AuditDuplicateDocNumbersUseCase $audit): int
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
            $this->print($audit->execute($from, $to));
        } catch (DomainException $e) {
            return $this->fails('Stopped: ' . \class_basename($e), 'the log, then run the command again');
        }

        return self::SUCCESS;
    }

    private function print(DuplicateDocNumbersResult $report): void
    {
        $this->line(self::HEADER);
        foreach ($report->duplicates as $duplicate) {
            $this->line(self::row($duplicate));
        }

        foreach ($report->counts() as $name => $count) {
            $this->output->getErrorStyle()->writeln("{$name}: {$count}");
        }
    }

    private static function row(DuplicateDocNumberDTO $duplicate): string
    {
        return \implode(',', [
            $duplicate->docNumber,
            ...\array_map(
                static fn(ExistingDocumentDTO $document): string => "{$document->type->value},{$document->qboId}",
                $duplicate->documents,
            ),
        ]);
    }

    private function fails(string $error, string $check): int
    {
        $this->output->getErrorStyle()->writeln(["<error>{$error}</error>", "  Check: {$check}"]);

        return self::FAILURE;
    }
}
